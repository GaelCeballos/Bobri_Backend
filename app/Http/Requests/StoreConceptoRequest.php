<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el alta de un concepto (lo que se compra o renta en la obra).
 *
 * Lo pueden crear el Administrador y el Trabajador. La categoría se indica de
 * UNA de dos formas:
 *  - `categoria_id`: una categoría que ya existe.
 *  - `categoria_nombre`: se busca o se crea en la misma petición. Útil cuando
 *    el trabajador captura sin señal y al sincronizar manda todo junto.
 *
 * Si ya existe un concepto con la misma huella normalizada no es un error:
 * el controlador devuelve el existente en lugar de duplicarlo.
 */
class StoreConceptoRequest extends FormRequest
{
    /**
     * Cualquier usuario con sesión puede crear conceptos.
     *
     * La ruta ya exige sesión activa con `check.role`; aquí no se restringe por
     * rol porque ambos roles agregan conceptos al capturar gastos.
     */
    public function authorize(): bool
    {
        return auth('api')->check();
    }

    /**
     * Reglas de validación.
     *
     * - `required_without`: hay que mandar `categoria_id` o `categoria_nombre`.
     * - `prohibits`: pero no los dos a la vez (sería ambiguo cuál usar).
     * - `regex:/[\pL\pN]/u`: al menos una letra o número; un nombre como "---"
     *   no tiene huella posible y provocaría un error 500.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200', 'regex:/[\pL\pN]/u'],
            'categoria_id' => ['required_without:categoria_nombre', 'prohibits:categoria_nombre', 'integer', 'exists:categorias,id'],
            'categoria_nombre' => ['required_without:categoria_id', 'string', 'max:100', 'regex:/[\pL\pN]/u'],
            'unidad_medida' => ['sometimes', 'string', 'max:50'],
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
            'nombre' => 'nombre del concepto',
            'categoria_id' => 'categoría',
            'categoria_nombre' => 'categoría',
            'unidad_medida' => 'unidad de medida',
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
            'nombre.required' => 'El nombre del concepto es obligatorio.',
            'nombre.string' => 'El nombre del concepto debe ser texto.',
            'nombre.max' => 'El nombre del concepto no puede tener más de 200 caracteres.',
            'nombre.regex' => 'El nombre del concepto debe contener letras o números.',
            'categoria_id.required_without' => 'Indica la categoría: elige una existente o escribe una nueva.',
            'categoria_nombre.required_without' => 'Indica la categoría: elige una existente o escribe una nueva.',
            'categoria_id.prohibits' => 'Envía categoria_id o categoria_nombre, no ambos.',
            'categoria_id.integer' => 'La categoría seleccionada no es válida.',
            'categoria_id.exists' => 'La categoría seleccionada no existe.',
            'categoria_nombre.string' => 'El nombre de la categoría debe ser texto.',
            'categoria_nombre.max' => 'El nombre de la categoría no puede tener más de 100 caracteres.',
            'categoria_nombre.regex' => 'El nombre de la categoría debe contener letras o números.',
            'unidad_medida.string' => 'La unidad de medida debe ser texto.',
            'unidad_medida.max' => 'La unidad de medida no puede tener más de 50 caracteres.',
        ];
    }
}
