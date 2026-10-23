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
     * - `regex:/[\pL\pN]/u`: al menos una letra o número (rechaza nombres como "---").
     * - `decimal:0,2`: como máximo 2 decimales (centavos); "1500.555" se rechaza en vez de redondearse.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            'cliente' => ['required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            // Dirección o referencia libre ("Calle Colón 45, Villa de Álvarez"); es opcional
            'ubicacion' => ['nullable', 'string', 'max:1000'],
            // Mayor que 0: el semáforo divide el saldo entre el contrato. El tope es el de decimal(12,2)
            'importe_contrato' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            // Opcional: si no se envía, la base de datos pone 'Activa'
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
