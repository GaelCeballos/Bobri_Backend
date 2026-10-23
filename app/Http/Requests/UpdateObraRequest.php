<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida la edición de una obra (datos del contrato o estado Activa/Finalizada).
 *
 * Solo el Administrador. Todos los campos son opcionales: se cambia únicamente lo que se envía.
 * Las obras no se borran: al terminar se marcan Finalizada.
 */
class UpdateObraRequest extends FormRequest
{
    /**
     * Solo un Administrador puede editar obras.
     *
     * La ruta ya está protegida con `check.role:Administrador`; esta revisión es
     * una segunda barrera por si la petición se reutilizara en otra ruta.
     */
    public function authorize(): bool
    {
        return auth('api')->user()?->isAdmin() ?? false;
    }

    /**
     * Reglas de validación.
     *
     * - `sometimes`: el campo solo se valida si viene en la petición.
     * - `required` junto con `sometimes`: si viene, no puede ir vacío (un PATCH con nombre "" no debe borrar el nombre).
     * - `regex`: al menos una letra o número.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            'cliente' => ['sometimes', 'required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            // null borra la ubicación
            'ubicacion' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Puede cambiar por ampliaciones del contrato; siempre mayor que 0 (el semáforo divide entre él)
            'importe_contrato' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            // Finalizada: deja de aparecer al capturar gastos; se puede reactivar
            'estado' => ['sometimes', 'required', Rule::in(['Activa', 'Finalizada'])],
        ];
    }

    /**
     * Nombres de los campos en los mensajes de error.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre de la obra',
            'cliente' => 'cliente',
            'ubicacion' => 'ubicación',
            'importe_contrato' => 'importe del contrato',
            'estado' => 'estado',
        ];
    }

    /**
     * Mensajes propios solo donde el genérico de lang/es no se entiende
     * ("El formato del campo … es inválido", "debe tener 0-2 decimales").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.regex' => 'El nombre de la obra debe contener letras o números.',
            'cliente.regex' => 'El cliente debe contener letras o números.',
            'importe_contrato.decimal' => 'El importe del contrato admite como máximo 2 decimales (centavos).',
        ];
    }
}
