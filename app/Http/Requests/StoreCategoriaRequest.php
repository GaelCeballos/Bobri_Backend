<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el alta de una categoría de gasto.
 *
 * La pueden crear el Administrador y el Trabajador (al capturar un gasto).
 * Si ya existe una categoría con la misma huella normalizada no es un error:
 * el controlador devuelve la existente en lugar de duplicarla.
 */
class StoreCategoriaRequest extends FormRequest
{
    /**
     * Cualquier usuario con sesión puede crear categorías.
     *
     * La ruta ya exige sesión activa con `check.role`; aquí no se restringe por
     * rol porque ambos roles pueden agregar categorías al catálogo.
     */
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    /**
     * Reglas de validación.
     *
     * `regex:/[\pL\pN]/u` exige al menos una letra o número (en cualquier idioma):
     * un nombre como "---" no tiene huella posible y provocaría un error 500.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100', 'regex:/[\pL\pN]/u'],
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
            'nombre' => 'nombre de la categoría',
        ];
    }

    /**
     * Mensajes en español (el proyecto aún no tiene traducciones en lang/es).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la categoría es obligatorio.',
            'nombre.string' => 'El nombre de la categoría debe ser texto.',
            'nombre.max' => 'El nombre de la categoría no puede tener más de 100 caracteres.',
            'nombre.regex' => 'El nombre de la categoría debe contener letras o números.',
        ];
    }
}
