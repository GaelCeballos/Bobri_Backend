<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Valida el rechazo de un ticket por el Administrador.
 *
 * El motivo es obligatorio: el trabajador lo ve en su historial ("Foto borrosa, vuelve a
 * tomarla") y sabe qué corregir. Al rechazar, la foto se borra ({@see \App\Support\FotoTicket::borrar}).
 */
class RechazarTicketRequest extends FormRequest
{
    /**
     * Aplica la TicketPolicy (solo el Administrador). `Gate::authorize` lanza el 403 con el
     * mensaje de la Policy; con un simple `false` el mensaje sería el genérico.
     */
    public function authorize(): bool
    {
        Gate::authorize('rechazar', $this->ticket());

        return true;
    }

    /**
     * Reglas de validación.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Debe tener al menos una letra o número: "..." no le explica nada al trabajador
            'motivo_rechazo' => ['required', 'string', 'max:500', 'regex:/[\pL\pN]/u'],
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
            'motivo_rechazo' => 'motivo del rechazo',
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
            'motivo_rechazo.regex' => 'Escribe el motivo del rechazo para que el trabajador sepa qué corregir.',
        ];
    }

    /**
     * Ticket de la ruta (`/tickets/{ticket}`), ya resuelto por Laravel.
     */
    private function ticket(): Ticket
    {
        return $this->route('ticket');
    }
}
