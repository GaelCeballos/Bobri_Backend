<?php

namespace App\Http\Requests;

use App\Models\Concepto;
use App\Support\NormalizadorTexto;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la corrección de un concepto existente (nombre, categoría o unidad).
 *
 * Solo el Administrador. Todos los campos son opcionales: se cambia únicamente
 * lo que se envía.
 */
class UpdateConceptoRequest extends FormRequest
{
    /**
     * Solo un Administrador puede corregir conceptos.
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
     * - `bail`: se detiene en el primer error del campo, para que la regla
     *   `nombreNoDuplicado` nunca reciba algo que no sea texto.
     * - `regex:/[\pL\pN]/u`: al menos una letra o número.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['bail', 'sometimes', 'string', 'max:200', 'regex:/[\pL\pN]/u', $this->nombreNoDuplicado(...)],
            'categoria_id' => ['sometimes', 'integer', 'exists:categorias,id'],
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
            'nombre.string' => 'El nombre del concepto debe ser texto.',
            'nombre.max' => 'El nombre del concepto no puede tener más de 200 caracteres.',
            'nombre.regex' => 'El nombre del concepto debe contener letras o números.',
            'categoria_id.integer' => 'La categoría seleccionada no es válida.',
            'categoria_id.exists' => 'La categoría seleccionada no existe.',
            'unidad_medida.string' => 'La unidad de medida debe ser texto.',
            'unidad_medida.max' => 'La unidad de medida no puede tener más de 50 caracteres.',
        ];
    }

    /**
     * Regla propia: el nombre nuevo no puede coincidir (ya normalizado) con OTRO concepto.
     *
     * Ejemplo: si se renombra "Compra de pala" a "PALA" y ya existe "Pala", se
     * responde 422 sugiriendo fusionar. Sin esta regla, el índice único de la base
     * de datos rechazaría el cambio con un error 500 difícil de entender.
     *
     * Se pasa a `rules()` como callable (`$this->nombreNoDuplicado(...)`); Laravel
     * la llama con el nombre del campo, su valor y la función para reportar el error.
     */
    private function nombreNoDuplicado(string $atributo, mixed $valor, Closure $fail): void
    {
        /** @var Concepto $concepto  Concepto que se edita (route model binding de {concepto}) */
        $concepto = $this->route('concepto');

        $otro = Concepto::where('nombre_normalizado', NormalizadorTexto::normalizar((string) $valor))
            ->whereKeyNot($concepto->getKey())
            ->first(['id', 'nombre']);

        if ($otro) {
            $fail("Ya existe el concepto «{$otro->nombre}». Si son el mismo, usa la opción de fusionar conceptos.");
        }
    }
}
