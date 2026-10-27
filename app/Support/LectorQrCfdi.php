<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Lee el texto del código QR impreso en una factura electrónica del SAT (CFDI).
 *
 * El teléfono escanea el QR y manda su texto tal cual en `qr_payload`. Ese texto viene de
 * fuera (cualquier QR, o una petición armada a mano), así que aquí se valida campo por
 * campo antes de guardar nada.
 *
 * Formatos que imprime el SAT:
 *   CFDI 3.3 y 4.0: https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?id=<UUID>&re=<RFC emisor>&rr=<RFC receptor>&tt=<total>&fe=<8 caracteres del sello>
 *   CFDI 3.2:       ?re=<RFC emisor>&rr=<RFC receptor>&tt=0000001500.000000&id=<UUID>
 *
 * El QR NO trae el XML ni el PDF: solo identifica la factura. Su descarga es de la rama
 * `feature/DescargaSAT` (descarga masiva con la e.firma de la constructora).
 *
 * @package App\Support
 */
final class LectorQrCfdi
{
    /**
     * Único dominio aceptado cuando el QR trae una URL: así un QR de otro sitio
     * (publicidad, un enlace malicioso) no se registra como factura.
     */
    private const HOST_SAT = 'verificacfdi.facturaelectronica.sat.gob.mx';

    /**
     * Un QR de CFDI mide ~150 caracteres; el tope evita procesar textos enormes.
     */
    private const LONGITUD_MAXIMA = 1000;

    /**
     * RFC: 3 letras (persona moral) o 4 (persona física), fecha AAMMDD y homoclave de 3.
     * Incluye Ñ y & porque el SAT los permite en las letras iniciales.
     */
    private const PATRON_RFC = '/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u';

    private const PATRON_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Convierte el texto del QR en datos validados.
     *
     * @param string $texto Texto leído del QR, sin modificar.
     * @return DatosQrCfdi
     * @throws InvalidArgumentException Si no es un QR de factura del SAT válido; el mensaje
     *                                  está en español y se puede mostrar al usuario.
     */
    public static function leer(string $texto): DatosQrCfdi
    {
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > self::LONGITUD_MAXIMA) {
            throw new InvalidArgumentException('El código QR no corresponde a una factura del SAT.');
        }

        $parametros = self::parametros($texto);

        $uuid = strtolower(trim($parametros['id'] ?? ''));
        $rfcEmisor = mb_strtoupper(trim($parametros['re'] ?? ''));
        $rfcReceptor = mb_strtoupper(trim($parametros['rr'] ?? ''));
        $total = trim($parametros['tt'] ?? '');

        if (! preg_match(self::PATRON_UUID, $uuid)) {
            throw new InvalidArgumentException('El código QR no trae un folio fiscal (UUID) válido.');
        }

        if (! preg_match(self::PATRON_RFC, $rfcEmisor) || ! preg_match(self::PATRON_RFC, $rfcReceptor)) {
            throw new InvalidArgumentException('El código QR no trae un RFC válido.');
        }

        // CFDI 3.2 rellena con ceros ("0000001500.000000"); 3.3/4.0 no. Ambos son números
        // sin signo con punto decimal opcional
        if (! preg_match('/^\d{1,18}(\.\d{1,6})?$/', $total)) {
            throw new InvalidArgumentException('El código QR no trae un total válido.');
        }

        return new DatosQrCfdi(
            uuid: $uuid,
            rfcEmisor: $rfcEmisor,
            rfcReceptor: $rfcReceptor,
            total: number_format((float) $total, 2, '.', ''),
        );
    }

    /**
     * Separa los parámetros (`id`, `re`, `rr`, `tt`, `fe`) del texto del QR.
     *
     * Si el texto es una URL, debe ser del dominio del SAT. Algunos lectores entregan
     * `&amp;` en lugar de `&`; se corrige antes de separar.
     *
     * @return array<string, string> Parámetros con la clave en minúsculas.
     */
    private static function parametros(string $texto): array
    {
        $texto = str_replace('&amp;', '&', $texto);

        if (str_starts_with($texto, '?')) {
            // Formato CFDI 3.2: solo la cadena de parámetros
            $consulta = substr($texto, 1);
        } else {
            $url = parse_url($texto);
            $host = strtolower($url['host'] ?? '');
            $esquema = strtolower($url['scheme'] ?? '');

            if ($host !== self::HOST_SAT || ! in_array($esquema, ['http', 'https'], true)) {
                throw new InvalidArgumentException('El código QR no corresponde a una factura del SAT.');
            }

            $consulta = $url['query'] ?? '';
        }

        parse_str($consulta, $parametros);

        // parse_str acepta arreglos (`id[]=…`): solo se conservan los valores de texto
        $parametros = array_filter($parametros, 'is_string');

        return array_change_key_case($parametros, CASE_LOWER);
    }
}
