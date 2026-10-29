<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Support\DatosQrCfdi;
use App\Support\LectorQrCfdi;
use App\Support\ZonaHoraria;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Valida la corrección de un ticket por el Administrador, viendo la foto (PUT).
 *
 * Es un PUT: se manda el ticket COMPLETO como debe quedar. Los renglones que no vengan en
 * la lista se borran (p. ej. un "DESCUENTO" que no es un producto) y los que vengan sin
 * `id` se agregan. Así el front envía la tabla tal como la ve, sin calcular diferencias.
 *
 * Es un borrador: cantidad, unidad, monto o concepto pueden quedar vacíos y se guardan.
 * Que el ticket esté completo se exige al VALIDAR, no aquí, para que el admin pueda
 * guardar su avance e ir corrigiendo por partes.
 *
 * `descripcion_leida` no se recibe: es el texto que se leyó de la foto y sirve para rastrear
 * de dónde salió cada gasto. El admin corrige el concepto, no lo leído.
 */
class UpdateTicketRequest extends FormRequest
{
    /** Igual que el Job LeerTicket: más renglones no es un ticket de compra normal */
    private const MAX_RENGLONES = 100;

    /**
     * Factura leída del QR. Solo tiene valor si la petición trae un `qr_payload` nuevo.
     */
    private ?DatosQrCfdi $factura = null;

    /**
     * Aplica la TicketPolicy (solo el Administrador). `Gate::authorize` lanza el 403 con el
     * mensaje de la Policy; con un simple `false` el mensaje sería el genérico.
     */
    public function authorize(): bool
    {
        Gate::authorize('update', $this->ticket());

        return true;
    }

    /**
     * El RFC se guarda en mayúsculas y sin espacios: así se compara igual que el que se lee
     * del QR ("Proveedores con más pagos" agrupa por RFC).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('rfc_emisor'))) {
            $this->merge(['rfc_emisor' => mb_strtoupper(preg_replace('/\s+/', '', $this->input('rfc_emisor')))]);
        }
    }

    /**
     * Reglas de validación.
     *
     * - `renglones.*.id`: solo renglones de ESTE ticket. Sin el `where`, el admin podría
     *   mandar el id de un renglón de otro ticket y moverlo o borrarlo por error.
     * - `concepto_id` o `concepto_nombre` (concepto nuevo que se crea al validar), no los dos.
     * - `qr_payload` con `sometimes`: si no viene, la factura no cambia; null la quita.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ticket = $this->ticket();

        return [
            // Se puede mover a otra obra Activa, o dejar la que tiene aunque ya se haya finalizado
            'obra_id' => ['required', 'integer', Rule::exists('obras', 'id')
                ->where(fn ($query) => $query->where('estado', 'Activa')->orWhere('id', $ticket->obra_id))],
            'categoria_id' => ['required', 'integer', 'exists:categorias,id'],
            'tipo_pago' => ['required', Rule::in(['Efectivo', 'Tarjeta', 'Transferencia'])],
            // "Hoy" en la zona del dispositivo (cabecera X-Zona-Horaria; si falta, CDMX)
            'fecha_compra' => ['bail', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.ZonaHoraria::hoy($this)],
            'proveedor' => ['nullable', 'string', 'max:200'],
            'rfc_emisor' => ['nullable', 'string', 'regex:'.LectorQrCfdi::PATRON_RFC],
            'total' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            // bail: si no es texto, se detiene antes de que la regla del QR intente leerlo
            'qr_payload' => ['bail', 'sometimes', 'nullable', 'string', 'max:1000', $this->qrDeFactura(...)],

            'renglones' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_RENGLONES],
            'renglones.*' => ['array:id,concepto_id,concepto_nombre,cantidad,unidad_medida,monto'],
            'renglones.*.id' => ['nullable', 'integer', 'distinct',
                Rule::exists('ticket_renglones', 'id')->where('ticket_id', $ticket->id)],
            'renglones.*.concepto_id' => ['nullable', 'integer', 'exists:conceptos,id', 'prohibits:renglones.*.concepto_nombre'],
            // Al menos una letra o número: "---" no sirve como nombre de concepto
            'renglones.*.concepto_nombre' => ['nullable', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            'renglones.*.cantidad' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'renglones.*.unidad_medida' => ['nullable', 'string', 'max:50'],
            'renglones.*.monto' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
        ];
    }

    /**
     * Factura leída del QR, para que el controlador guarde `uuid_sat` y `rfc_emisor`.
     * Solo tiene valor después de validar y si la petición trae un QR nuevo.
     */
    public function factura(): ?DatosQrCfdi
    {
        return $this->factura;
    }

    /**
     * Nombres de los campos en los mensajes de error. En los renglones, `:position` es el
     * número del renglón empezando en 1 ("el monto del renglón 3"), como lo ve el admin.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'obra_id' => 'obra',
            'categoria_id' => 'categoría',
            'tipo_pago' => 'tipo de pago',
            'fecha_compra' => 'fecha de compra',
            'proveedor' => 'proveedor',
            'rfc_emisor' => 'RFC del proveedor',
            'total' => 'total',
            'qr_payload' => 'código QR',
            'renglones' => 'renglones',
            'renglones.*.id' => 'renglón :position',
            'renglones.*.concepto_id' => 'concepto del renglón :position',
            'renglones.*.concepto_nombre' => 'concepto nuevo del renglón :position',
            'renglones.*.cantidad' => 'cantidad del renglón :position',
            'renglones.*.unidad_medida' => 'unidad del renglón :position',
            'renglones.*.monto' => 'monto del renglón :position',
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
            'fecha_compra.date_format' => 'La fecha de compra debe tener el formato AAAA-MM-DD.',
            'fecha_compra.before_or_equal' => 'La fecha de compra no puede ser futura.',
            'rfc_emisor.regex' => 'El RFC del proveedor no tiene un formato válido.',
            'total.decimal' => 'El total admite como máximo 2 decimales (centavos).',
            'renglones.required' => 'El ticket debe tener al menos un renglón.',
            'renglones.max' => 'El ticket no puede tener más de '.self::MAX_RENGLONES.' renglones.',
            'renglones.*.array' => 'El renglón :position trae campos que no se pueden modificar.',
            'renglones.*.id.exists' => 'El renglón :position no pertenece a este ticket.',
            'renglones.*.id.distinct' => 'El renglón :position está repetido.',
            'renglones.*.concepto_id.exists' => 'El concepto del renglón :position no existe.',
            'renglones.*.concepto_id.prohibits' => 'En el renglón :position elige un concepto existente o escribe uno nuevo, no los dos.',
            'renglones.*.concepto_nombre.regex' => 'El concepto nuevo del renglón :position debe tener letras o números.',
            'renglones.*.cantidad.decimal' => 'La cantidad del renglón :position admite como máximo 2 decimales.',
            'renglones.*.monto.decimal' => 'El monto del renglón :position admite como máximo 2 decimales (centavos).',
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

    /**
     * Ticket de la ruta (`/tickets/{ticket}`), ya resuelto por Laravel.
     */
    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
