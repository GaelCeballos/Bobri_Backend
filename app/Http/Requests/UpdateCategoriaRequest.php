<?php

namespace App\Http\Requests;

use App\Models\Categoria;
use App\Support\NormalizadorTexto;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la corrección de una categoría existente (nombre).
 *
 * Solo el Administrador. El campo nombre es opcional: se cambia únicamente
 * si se envía. Renombrar una categoría actualiza automáticamente todas las gráficas
 * porque los conceptos la referencian por id.
 */
class UpdateCategoriaRequest extends FormRequest
{
    /**
     * Solo un Administrador puede corregir categorías.
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
            'nombre' => ['bail', 'sometimes', 'string', 'max:100', 'regex:/[\pL\pN]/u', $this->nombreNoDuplicado(...)],
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
            'nombre.string' => 'El nombre de la categoría debe ser texto.',
            'nombre.max' => 'El nombre de la categoría no puede tener más de 100 caracteres.',
            'nombre.regex' => 'El nombre de la categoría debe contener letras o números.',
        ];
    }

    /**
     * Regla propia: el nombre nuevo no puede coincidir (ya normalizado) con OTRA categoría.
     *
     * Ejemplo: si se renombra "Herramienta menor" a "MATERIALES" y ya existe
     * "Materiales", se responde 422. Renombrar la misma categoría con otro formato
     * ("Materiales" → "MATERIALES") sí se permite, porque se excluye a sí misma.
     * Sin esta regla, el índice único de la base de datos rechazaría el cambio con
     * un error 500 difícil de entender.
     *
     * Se pasa a `rules()` como callable (`$this->nombreNoDuplicado(...)`); Laravel
     * la llama con el nombre del campo, su valor y la función para reportar el error.
     */
    private function nombreNoDuplicado(string $atributo, mixed $valor, Closure $fail): void
    {
        /** @var Categoria $categoria  Categoría que se edita (route model binding de {categoria}) */
        $categoria = $this->route('categoria');

        $otra = Categoria::where('nombre_normalizado', NormalizadorTexto::normalizar((string) $valor))
            ->whereKeyNot($categoria->getKey())
            ->first(['id', 'nombre']);

        if ($otra) {
            $fail("Ya existe la categoría «{$otra->nombre}». Elige otro nombre.");
        }
    }
}
