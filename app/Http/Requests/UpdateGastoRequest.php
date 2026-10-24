<?php

namespace App\Http\Requests;

use App\Models\Gasto;
use App\Support\DatosQrCfdi;
use App\Support\LectorQrCfdi;
use App\Support\ZonaHoraria;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Valida la corrección de un gasto ya registrado.
 *
 * Lo corrigen el Administrador o el trabajador que lo registró ({@see \App\Policies\GastoPolicy}).
 * Todos los campos son opcionales: se cambia únicamente lo que se envía. `uuid_cliente`
 * no se puede cambiar (identifica la captura original).
 */
class UpdateGastoRequest extends FormRequest
{
    /**
     * Factura leída del QR. Solo tiene valor si la petición trae un `qr_payload` nuevo.
     */
    private ?DatosQrCfdi $factura = null;

    /**
     * Aplica la GastoPolicy. `Gate::authorize` lanza el 403 con el mensaje de la Policy
     * ("Solo puedes corregir los gastos que tú registraste."); con un simple `false` el
     * mensaje sería el genérico.
     */
    public function authorize(): bool
    {
        Gate::authorize('update', $this->gasto());

        return true;
    }

    /**
     * Reglas de validación (las mismas que al registrar, con `sometimes`).
     *
     * - `sometimes`: el campo solo se valida si viene en la petición.
     * - `required` junto con `sometimes`: si viene, no puede ir vacío.
     * - La pareja cantidad/unidad se revisa en {@see self::after()} contra el gasto ya
     *   guardado: un PATCH que solo manda `cantidad: null` dejaría la unidad sola.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $obraActual = $this->gasto()->obra_id;

        return [
            // Se puede mover a otra obra Activa. Si la obra ya se finalizó, se pueden corregir
            // sus gastos sin moverlos (por eso se acepta también la obra actual)
            'obra_id' => ['sometimes', 'required', 'integer', Rule::exists('obras', 'id')
                ->where(fn ($query) => $query->where('estado', 'Activa')->orWhere('id', $obraActual))],
            'concepto_id' => ['sometimes', 'required', 'integer', 'exists:conceptos,id'],
            'cantidad' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'unidad_medida' => ['sometimes', 'nullable', 'string', 'max:50'],
            'monto_total' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'tipo_pago' => ['sometimes', 'required', Rule::in(['Efectivo', 'Tarjeta', 'Transferencia'])],
            // "Hoy" en la zona del dispositivo (cabecera X-Zona-Horaria; si falta, CDMX)
            'fecha_gasto' => ['bail', 'sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.ZonaHoraria::hoy($this)],
            // null quita la factura del gasto (p. ej. se escaneó el QR de otra compra)
            'qr_payload' => ['bail', 'sometimes', 'nullable', 'string', 'max:1000', $this->qrDeFactura(...)],
        ];
    }

    /**
     * Revisa la pareja cantidad/unidad con los valores FINALES: lo que trae la petición y,
     * para lo que no trae, lo que ya tiene el gasto. Sin esto, la restricción de la base de
     * datos rechazaría el cambio con un error 500.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $gasto = $this->gasto();
                $cantidad = $this->exists('cantidad') ? $this->input('cantidad') : $gasto->cantidad;
                $unidad = $this->exists('unidad_medida') ? $this->input('unidad_medida') : $gasto->unidad_medida;

                if (($cantidad === null) !== ($unidad === null)) {
                    $campo = $cantidad === null ? 'cantidad' : 'unidad_medida';
                    $validator->errors()->add($campo, $cantidad === null
                        ? 'Si indicas la unidad, indica también la cantidad.'
                        : 'Si indicas la cantidad, indica también la unidad.');
                }
            },
        ];
    }

    /**
     * Factura leída del QR nuevo (null si la petición no trae QR o lo quita).
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
            'cantidad.decimal' => 'La cantidad admite como máximo 2 decimales.',
            'monto_total.decimal' => 'El monto admite como máximo 2 decimales (centavos).',
            'fecha_gasto.date_format' => 'La fecha del gasto debe tener el formato AAAA-MM-DD.',
            'fecha_gasto.before_or_equal' => 'La fecha del gasto no puede ser futura.',
        ];
    }

    /**
     * Gasto que se corrige (route model binding de {gasto}).
     */
    private function gasto(): Gasto
    {
        return $this->route('gasto');
    }

    /**
     * Regla propia: el QR nuevo debe ser de una factura del SAT ({@see LectorQrCfdi}).
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
