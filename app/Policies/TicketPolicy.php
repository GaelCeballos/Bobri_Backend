<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Permisos sobre los tickets por foto (decisión del dueño):
 *
 * - Subir un ticket: cualquier usuario con sesión (no pasa por aquí; lo resuelve la ruta).
 * - Ver la bandeja, el detalle con la foto y los posibles duplicados, corregir, validar
 *   o rechazar: solo el Administrador. Él compara los renglones con la foto antes de que
 *   se conviertan en gastos.
 * - El trabajador consulta el estado de los suyos en GET /tickets/mios (sin la foto).
 *
 * Aquí solo se decide QUIÉN. Si el ticket todavía se puede corregir (su estado) lo revisa
 * el controlador dentro de la transacción, con la fila bloqueada: si dos administradores
 * validan a la vez, solo uno lo consigue (el otro recibe 409, no 403).
 *
 * @package App\Policies
 */
class TicketPolicy
{
    /**
     * Bandeja de tickets por revisar (todas las obras y trabajadores).
     */
    public function viewAny(User $user): Response
    {
        return $this->soloAdmin($user, 'consultar los tickets');
    }

    /**
     * Detalle con la foto (enlace temporal) y los posibles duplicados.
     */
    public function view(User $user, Ticket $ticket): Response
    {
        return $this->soloAdmin($user, 'ver el detalle de los tickets');
    }

    /**
     * Corregir el encabezado y los renglones.
     */
    public function update(User $user, Ticket $ticket): Response
    {
        return $this->soloAdmin($user, 'corregir tickets');
    }

    /**
     * Convertir los renglones en gastos.
     */
    public function validar(User $user, Ticket $ticket): Response
    {
        return $this->soloAdmin($user, 'validar tickets');
    }

    /**
     * Rechazar el ticket (borra su foto).
     */
    public function rechazar(User $user, Ticket $ticket): Response
    {
        return $this->soloAdmin($user, 'rechazar tickets');
    }

    /**
     * Permite solo al Administrador; el mensaje dice qué intentó hacer el usuario.
     */
    private function soloAdmin(User $user, string $accion): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny("Solo el administrador puede {$accion}.");
    }
}
