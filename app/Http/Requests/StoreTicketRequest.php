<?php

namespace App\Http\Requests;

use App\Support\DatosQrCfdi;
use App\Support\LectorQrCfdi;
use App\Support\ZonaHoraria;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Valida la subida de un ticket por foto (petición multipart desde el teléfono).
 *
 * Lo suben el Administrador y el Trabajador. Además de la foto llegan la obra, la categoría
 * (solo se asigna a los conceptos NUEVOS al validar), el tipo de pago y el día en que se tomó
 * la foto. Los productos NO llegan aquí: los lee después el Job LeerTicket.
 *
 * La foto se revisa a fondo al guardarla ({@see \App\Support\FotoTicket}): aquí solo se
 * filtran el tipo y el peso para rechazar pronto lo que claramente no sirve.
 */
class StoreTicketRequest extends FormRequest
{
    /**
     * Factura leída del QR (null si el ticket no trae QR).
     */
    private ?DatosQrCfdi $factura = null;

    /**
     * Cualquier usuario con sesión puede subir tickets.
     *
     * La ruta ya exige sesión activa con `check.role`; aquí no se restringe por rol
     * porque ambos roles capturan tickets.
     */
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    /**
     * Reglas de validación.
     *
     * - `foto`: `mimes` revisa el tipo por el CONTENIDO del archivo, no por su nombre. 5 MB
     *   alcanzan para una foto sin reducir; el front normalmente la manda de ~300 KB.
     * - `fecha_captura`: el día en que se tomó la foto según el teléfono. Sin señal, la foto
     *   puede llegar días después; por eso no se usa la fecha de llegada.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Lo genera el teléfono al capturar; si un envío sin señal se reintenta, el
            // controlador lo reconoce y devuelve el ticket ya creado en lugar de duplicarlo
            'uuid_cliente' => ['required', 'uuid'],
            // Solo obras Activa: en una obra Finalizada ya no se registran gastos
            'obra_id' => ['required', 'integer', Rule::exists('obras', 'id')->where('estado', 'Activa')],
            'categoria_id' => ['required', 'integer', 'exists:categorias,id'],
            'tipo_pago' => ['required', Rule::in(['Efectivo', 'Tarjeta', 'Transferencia'])],
            // "Hoy" en la zona del dispositivo (cabecera X-Zona-Horaria; si falta, CDMX)
            'fecha_captura' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.ZonaHoraria::hoy($this)],
            // bail: si la subida falló o no es archivo, no sigue revisando tipo y peso
            'foto' => ['bail', 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // QR de la factura del ticket completo, si lo trae (opcional)
            'qr_payload' => ['bail', 'nullable', 'string', 'max:1000', $this->qrDeFactura(...)],
        ];
    }

    /**
     * Factura leída del QR, para que el controlador guarde `uuid_sat` y `rfc_emisor`.
     * Solo tiene valor después de validar y si el ticket trae QR.
     */
    public function factura(): ?DatosQrCfdi
    {
        return $this->factura;
    }

    /**
     * Nombres de los campos en los mensajes de error.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'uuid_cliente' => 'identificador de la captura',
            'obra_id' => 'obra',
            'categoria_id' => 'categoría',
            'tipo_pago' => 'tipo de pago',
            'fecha_captura' => 'fecha de la foto',
            'foto' => 'foto',
            'qr_payload' => 'código QR',
        ];
    }

    /**
     * Mensajes propios solo donde el genérico de lang/es no se entiende.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'obra_id.exists' => 'La obra no existe o ya está finalizada.',
            'categoria_id.exists' => 'La categoría seleccionada no existe.',
            'fecha_captura.date_format' => 'La fecha de la foto debe tener el formato AAAA-MM-DD.',
            'fecha_captura.before_or_equal' => 'La fecha de la foto no puede ser futura.',
            // PHP rechazó el archivo antes de llegar a Laravel (muy pesado o subida cortada)
            'foto.uploaded' => 'La foto no se pudo subir. Intenta de nuevo.',
            'foto.mimes' => 'La foto debe ser JPG, PNG o WebP.',
            'foto.max' => 'La foto no puede pesar más de 5 MB.',
        ];
    }

    /**
     * Regla propia: el QR debe ser de una factura del SAT ({@see LectorQrCfdi}).
     *
     * Si es válido, guarda la factura leída para el controlador; si no, el mensaje del
     * lector ("El código QR no corresponde a una factura del SAT.") llega al usuario.
     */
    private function qrDeFactura(string $atributo, mixed $valor, Closure $fail): void
    {
        try {
            $this->factura = LectorQrCfdi::leer((string) $valor);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }
}
