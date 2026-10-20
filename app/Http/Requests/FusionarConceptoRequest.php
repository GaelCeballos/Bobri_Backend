<?php

namespace App\Http\Requests;

use App\Models\Concepto;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la fusión de un concepto duplicado (el de la ruta, {concepto}, p. ej. 'Compra de pala')
 * en otro concepto destino (p. ej. 'Pala'). Solo Administrador.
 *
 * El Administrador debe indicar el concepto destino al que se fusionará el concepto de la ruta.
 */
class FusionarConceptoRequest extends FormRequest
{
    /**
     * Solo un Administrador puede fusionar conceptos.
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
     * - `required`: el campo es obligatorio para la fusión.
     * - `bail`: se detiene en el primer error del campo, para que la regla
     *   `distintoDelOrigen` nunca reciba algo que no sea un número.
     * - `integer`: el valor debe ser un número entero.
     * - `exists`: el concepto destino debe existir en la base de datos.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'destino_id' => ['bail', 'required', 'integer', 'exists:conceptos,id', $this->distintoDelOrigen(...)],
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
            'destino_id' => 'concepto destino',
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
            'destino_id.required' => 'Indica el concepto con el que se va a fusionar.',
            'destino_id.integer' => 'El concepto destino no es válido.',
            'destino_id.exists' => 'El concepto destino no existe.',
        ];
    }

    /**
     * Regla propia: el concepto destino no puede ser el mismo que el de origen.
     *
     * Ejemplo: si se intenta fusionar 'Compra de pala' en 'Compra de pala', se
     * responde 422 indicando que no se puede fusionar un concepto consigo mismo.
     * Esto evitaría mover los gastos al mismo concepto y luego borrarlo, perdiendo el concepto.
     *
     * Se pasa a `rules()` como callable (`$this->distintoDelOrigen(...)`); Laravel
     * la llama con el nombre del campo, su valor y la función para reportar el error.
     */
    private function distintoDelOrigen(string $atributo, mixed $valor, Closure $fail): void
    {
        /** @var Concepto $concepto  Concepto que se fusionará (route model binding de {concepto}) */
        $concepto = $this->route('concepto');

        if ((int) $valor === $concepto->getKey()) {
            $fail('No se puede fusionar un concepto consigo mismo.');
        }
    }
}
