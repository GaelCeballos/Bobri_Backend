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
 * Valida el registro de un gasto (captura manual, con o sin QR de factura).
 *
 * Lo registran el Administrador y el Trabajador. El formulario tiene 4 campos
 * (Concepto · Cantidad · Unidad · Monto) más obra, tipo de pago y fecha:
 * - "Cemento de 50 kg" · 1 · tonelada · $3,200
 * - "Pago a electrico" · — · — · $1,500  (servicio: sin cantidad ni unidad)
 *
 * Si trae `qr_payload`, el QR se lee aquí mismo y el controlador obtiene la factura con
 * {@see self::factura()}. Los gastos capturados sin señal llegan después con la misma
 * forma; por eso la fecha la manda el teléfono (el día de la compra, no el del envío).
 */
class StoreGastoRequest extends FormRequest
{
    /**
     * Factura leída del QR (null si el gasto no trae QR).
     */
    private ?DatosQrCfdi $factura = null;

    /**
     * Cualquier usuario con sesión puede registrar gastos.
     *
     * La ruta ya exige sesión activa con `check.role`; aquí no se restringe por rol
     * porque ambos roles capturan gastos.
     */
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    /**
     * Reglas de validación.
     *
     * - `required_with` en cantidad y unidad: van en pareja (si va una, va la otra). Las dos
     *   vacías es un pago de mano de obra o servicio. La base de datos también lo exige.
     * - `decimal:0,2`: como máximo 2 decimales; "1500.555" se rechaza en vez de redondearse.
     * - `fecha_gasto`: puede ser pasada (captura atrasada o sin señal), nunca futura. Con
     *   `bail`, una fecha mal escrita da un solo error (no también "no puede ser futura").
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Lo genera el teléfono al capturar; si un envío sin señal se reintenta, el
            // controlador lo reconoce y devuelve el gasto ya creado en lugar de duplicarlo
            'uuid_cliente' => ['required', 'uuid'],
            // Solo obras Activa: en una obra Finalizada ya no se registran gastos
            'obra_id' => ['required', 'integer', Rule::exists('obras', 'id')->where('estado', 'Activa')],
            'concepto_id' => ['required', 'integer', 'exists:conceptos,id'],
            'cantidad' => ['nullable', 'required_with:unidad_medida', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'unidad_medida' => ['nullable', 'required_with:cantidad', 'string', 'max:50'],
            'monto_total' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'tipo_pago' => ['required', Rule::in(['Efectivo', 'Tarjeta', 'Transferencia'])],
            // "Hoy" en la zona del dispositivo (cabecera X-Zona-Horaria; si falta, CDMX)
            'fecha_gasto' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.ZonaHoraria::hoy($this)],
            // bail: si no es texto, se detiene antes de que la regla del QR intente leerlo
            'qr_payload' => ['bail', 'nullable', 'string', 'max:1000', $this->qrDeFactura(...)],
        ];
    }

    /**
     * Factura leída del QR, para que el controlador guarde `uuid_sat` y `rfc_emisor`.
     * Solo tiene valor después de validar y si el gasto trae QR.
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
            'concepto_id' => 'concepto',
            'cantidad' => 'cantidad',
            'unidad_medida' => 'unidad',
            'monto_total' => 'monto',
            'tipo_pago' => 'tipo de pago',
            'fecha_gasto' => 'fecha del gasto',
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
            'concepto_id.exists' => 'El concepto seleccionado no existe.',
            'cantidad.required_with' => 'Si indicas la unidad, indica también la cantidad.',
            'unidad_medida.required_with' => 'Si indicas la cantidad, indica también la unidad.',
            'cantidad.decimal' => 'La cantidad admite como máximo 2 decimales.',
            'monto_total.decimal' => 'El monto admite como máximo 2 decimales (centavos).',
            'fecha_gasto.date_format' => 'La fecha del gasto debe tener el formato AAAA-MM-DD.',
            'fecha_gasto.before_or_equal' => 'La fecha del gasto no puede ser futura.',
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
