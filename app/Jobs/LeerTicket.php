<?php

namespace App\Jobs;

use App\Contracts\LectorTicket;
use App\Models\Concepto;
use App\Models\Ticket;
use App\Support\FotoTicket;
use App\Support\LectorQrCfdi;
use App\Support\Lectura\LecturaFallida;
use App\Support\Lectura\LecturaTicket;
use App\Support\Lectura\RenglonLeido;
use App\Support\NormalizadorTexto;
use App\Support\ZonaHoraria;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Lee la foto de un ticket en segundo plano: `procesando` → `por_revisar` (o `error`).
 *
 * Corre en la cola (servicio `queue` de docker-compose) porque leer una foto tarda varios
 * segundos: el trabajador sube la foto, recibe respuesta al instante y sigue trabajando.
 *
 * Lo que devuelve el lector NO es confiable (OCR o IA pueden inventar o confundir datos):
 * cada valor se valida y, si no tiene sentido, se guarda vacío para que el Administrador lo
 * capture viendo la foto. Nunca se guarda un dato inválido.
 *
 * @package App\Jobs
 */
class LeerTicket implements ShouldQueue
{
    use Queueable;

    /** Intentos ante errores temporales (sin red, servicio caído) */
    public int $tries = 3;

    /** Espera entre intentos, en segundos: da tiempo a que el servicio se recupere */
    public array $backoff = [30, 120];

    /** Segundos máximos por intento (debe ser menor que el `--timeout` del worker) */
    public int $timeout = 90;

    /** Más renglones que esto no es un ticket de compra normal; se ignoran los sobrantes */
    private const MAX_RENGLONES = 100;

    /** Mensaje para el admin cuando falla por un error interno (no se le muestran detalles técnicos) */
    private const ERROR_GENERICO = 'No se pudo leer el ticket. Captura los productos a mano o pide otra foto.';

    /**
     * Recibe el id y no el modelo: el Job vuelve a leer el ticket de la base al ejecutarse,
     * así trabaja con su estado actual y no con una copia de cuando se encoló.
     */
    public function __construct(public readonly int $ticketId)
    {
    }

    public function handle(LectorTicket $lector): void
    {
        $ticket = Ticket::find($this->ticketId);

        // Ya se leyó (el Job se encoló dos veces) o el ticket ya no existe: no hay nada que hacer
        if ($ticket === null || $ticket->estado !== Ticket::PROCESANDO) {
            return;
        }

        if ($ticket->ruta_foto === null || ! Storage::disk(FotoTicket::DISCO)->exists($ticket->ruta_foto)) {
            $this->marcarError('No se encontró la foto del ticket. Pide al trabajador que la vuelva a tomar.');

            return;
        }

        try {
            $lectura = $lector->leer(Storage::disk(FotoTicket::DISCO)->get($ticket->ruta_foto));
        } catch (LecturaFallida $e) {
            // Definitivo (no es un ticket, está borrosa): reintentar no lo arreglaría
            $this->marcarError($e->getMessage());

            return;
        }
        // Cualquier otra excepción sale del Job: la cola reintenta y, al agotar los
        // intentos, llama a failed()

        $renglones = $this->renglones($lectura->renglones);

        if ($renglones === []) {
            $this->marcarError('No se encontraron productos en la foto. Captúralos a mano o pide otra foto.');

            return;
        }

        DB::transaction(function () use ($lectura, $renglones): void {
            // Bloquea la fila: si el Job corre dos veces a la vez, el segundo espera aquí,
            // ve que ya no está `procesando` y no duplica los renglones
            $ticket = Ticket::whereKey($this->ticketId)->lockForUpdate()->first();

            if ($ticket === null || $ticket->estado !== Ticket::PROCESANDO) {
                return;
            }

            $ticket->fecha_compra = $this->fecha($lectura->fecha);
            $ticket->proveedor = $this->texto($lectura->proveedor, 200);
            $ticket->rfc_emisor = $this->rfc($lectura->rfcEmisor);
            $ticket->total = $this->numero($lectura->total, 10);
            $this->factura($ticket, $lectura);

            $ticket->estado = Ticket::POR_REVISAR;
            $ticket->save();
            $ticket->renglones()->createMany($renglones);
        });
    }

    /**
     * La cola agotó los intentos (o el Job excedió su tiempo): el ticket pasa a `error` para
     * que no se quede "procesando" para siempre. El detalle técnico queda en `failed_jobs`.
     */
    public function failed(?Throwable $e): void
    {
        $this->marcarError(self::ERROR_GENERICO);
    }

    /**
     * Pasa el ticket a `error` solo si sigue `procesando`: con un UPDATE condicionado no
     * se pisa un ticket que otro proceso ya terminó de leer.
     */
    private function marcarError(string $mensaje): void
    {
        Ticket::whereKey($this->ticketId)
            ->where('estado', Ticket::PROCESANDO)
            ->update(['estado' => Ticket::ERROR, 'error_lectura' => $mensaje]);
    }

    /**
     * Limpia los renglones leídos y sugiere un concepto existente para cada uno.
     *
     * @param  list<RenglonLeido>  $leidos
     * @return list<array<string, mixed>> Atributos listos para `createMany`.
     */
    private function renglones(array $leidos): array
    {
        $renglones = [];

        foreach (array_slice($leidos, 0, self::MAX_RENGLONES) as $leido) {
            $descripcion = $this->texto($leido->descripcion, 255);

            // Un renglón sin texto no le dice nada al admin
            if ($descripcion === null) {
                continue;
            }

            $renglones[] = [
                'orden' => count($renglones) + 1,
                'descripcion_leida' => $descripcion,
                'cantidad' => $this->numero($leido->cantidad, 8),
                'unidad_medida' => $this->texto($leido->unidadMedida, 50),
                'monto' => $this->numero($leido->monto, 10),
            ];
        }

        $sugeridos = $this->conceptosSugeridos(array_column($renglones, 'descripcion_leida'));

        return array_map(
            fn (array $renglon): array => $renglon + ['concepto_id' => $sugeridos[$renglon['descripcion_leida']] ?? null],
            $renglones,
        );
    }

    /**
     * Busca, para cada descripción, un concepto del catálogo que se escriba igual
     * (`nombre_normalizado`) o, si no, con las mismas palabras clave. Es solo una
     * sugerencia: el admin la confirma o la cambia al revisar.
     *
     * Dos consultas en total, no dos por renglón: un ticket de 50 productos no hace 100 consultas.
     *
     * @param  list<string>  $descripciones
     * @return array<string, int> Descripción => id del concepto sugerido.
     */
    private function conceptosSugeridos(array $descripciones): array
    {
        $huellas = $claves = [];

        foreach ($descripciones as $descripcion) {
            $huellas[$descripcion] = NormalizadorTexto::normalizar($descripcion);
            $claves[$descripcion] = NormalizadorTexto::palabrasClave($descripcion);
        }

        $porHuella = Concepto::whereIn('nombre_normalizado', array_filter($huellas))
            ->pluck('id', 'nombre_normalizado');
        // Con la misma firma puede haber varios conceptos: se sugiere el más antiguo. pluck()
        // se queda con el ÚLTIMO de cada clave, por eso se ordena del más nuevo al más antiguo
        $porClave = Concepto::whereIn('palabras_clave', array_filter($claves))
            ->orderByDesc('id')
            ->pluck('id', 'palabras_clave');

        $sugeridos = [];

        foreach ($descripciones as $descripcion) {
            $id = $porHuella[$huellas[$descripcion]] ?? $porClave[$claves[$descripcion]] ?? null;

            if ($id !== null) {
                $sugeridos[$descripcion] = $id;
            }
        }

        return $sugeridos;
    }

    /**
     * Si el ticket trae el QR de una factura válida del SAT, guarda su folio y toma de ahí el
     * RFC y el total (son más confiables que lo leído del papel). Un QR inválido se ignora.
     *
     * Si otro ticket vigente ya tiene esa factura, no se guarda el folio (el índice único lo
     * rechazaría y se perdería toda la lectura); el aviso de duplicado se da al validar.
     */
    private function factura(Ticket $ticket, LecturaTicket $lectura): void
    {
        if ($lectura->qrPayload === null) {
            return;
        }

        try {
            $datos = LectorQrCfdi::leer($lectura->qrPayload);
        } catch (InvalidArgumentException) {
            return;
        }

        $ticket->qr_payload = $lectura->qrPayload;
        $ticket->rfc_emisor = $datos->rfcEmisor;
        $ticket->total = (float) $datos->total > 0 ? $datos->total : $ticket->total;

        $enOtroTicket = Ticket::where('uuid_sat', $datos->uuid)
            ->where('estado', '<>', Ticket::RECHAZADO)
            ->whereKeyNot($ticket->id)
            ->exists();

        $ticket->uuid_sat = $enOtroTicket ? null : $datos->uuid;
    }

    /**
     * Fecha "AAAA-MM-DD" real, del año 2000 en adelante y no futura. Se tolera un día de más
     * porque el "hoy" de México puede ir detrás del dispositivo (Quintana Roo va una hora adelante).
     */
    private function fecha(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {
            return null;
        }

        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $texto, ZonaHoraria::POR_DEFECTO);
        $manana = CarbonImmutable::now(ZonaHoraria::POR_DEFECTO)->addDay()->startOfDay();

        // createFromFormat acepta "2026-02-31" y lo pasa a marzo: se compara de vuelta con el texto
        if ($fecha === false || $fecha->format('Y-m-d') !== $texto || $fecha->year < 2000 || $fecha > $manana) {
            return null;
        }

        return $texto;
    }

    /**
     * Texto en una sola línea, sin caracteres de control ni bytes inválidos, recortado al
     * largo de la columna. null si queda vacío.
     */
    private function texto(?string $texto, int $maximo): ?string
    {
        $texto = mb_scrub((string) $texto, 'UTF-8');
        // Saltos de línea y tabuladores → un espacio; los demás caracteres de control
        // (invisibles, como \0) se quitan sin partir la palabra
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';
        $texto = trim(preg_replace('/\p{C}+/u', '', $texto) ?? '');

        return $texto === '' ? null : mb_substr($texto, 0, $maximo);
    }

    /**
     * RFC en mayúsculas con el formato del SAT; null si no lo cumple.
     */
    private function rfc(?string $texto): ?string
    {
        $rfc = mb_strtoupper(preg_replace('/[\s-]+/', '', (string) $texto) ?? '');

        return preg_match(LectorQrCfdi::PATRON_RFC, $rfc) ? $rfc : null;
    }

    /**
     * Número positivo con 2 decimales ("1234.50") que cabe en la columna; null si no lo es.
     *
     * Acepta "$1,234.50" (signo de pesos y comas de miles, como se imprimen en los tickets).
     * Un número negativo (descuento) se descarta: el admin decide qué hacer con ese renglón.
     *
     * @param int $enteros Dígitos enteros que admite la columna: decimal(12,2) → 10, decimal(10,2) → 8.
     */
    private function numero(?string $texto, int $enteros): ?string
    {
        $texto = str_replace(['$', ' '], '', trim((string) $texto));

        // Comas solo como separador de miles ("1,234,567.89"); "1,5" es ambiguo y se descarta
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $texto)) {
            $texto = str_replace(',', '', $texto);
        }

        if (! preg_match('/^\d{1,'.$enteros.'}(\.\d{1,6})?$/', $texto)) {
            return null;
        }

        $numero = number_format((float) $texto, 2, '.', '');

        // Redondear puede llevar 99999999.999 a 100000000.00, que ya no cabe
        return (float) $numero > 0 && strlen(strstr($numero, '.', true)) <= $enteros ? $numero : null;
    }
}
