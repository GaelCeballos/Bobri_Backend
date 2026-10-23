<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el alta de una obra (proyecto de construcción con su importe de contrato).
 *
 * Solo el Administrador da de alta obras.
 */
class StoreObraRequest extends FormRequest
{
    /**
     * Solo un Administrador puede dar de alta obras.
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
     * - `regex:/[\pL\pN]/u`: al menos una letra o número.
     * - `decimal:0,2`: permite números con hasta 2 decimales (centavos).
     * - `gt:0`: el importe del contrato debe ser mayor que 0.
     * - `max:9999999999.99`: el importe del contrato no puede superar 9,999,999,999.99.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            'cliente' => ['required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            'ubicacion' => ['nullable', 'string', 'max:1000'],
            'importe_contrato' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'estado' => ['sometimes', Rule::in(['Activa', 'Finalizada'])],
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
}
