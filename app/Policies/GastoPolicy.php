<?php

namespace App\Policies;

use App\Models\Gasto;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Permisos sobre los gastos ya registrados (decisión del dueño):
 *
 * - Ver la lista completa (todas las obras y trabajadores): solo el Administrador.
 *   El trabajador consulta solo los suyos en GET /gastos/mios.
 * - Corregir o borrar un gasto: el Administrador, o el trabajador que lo registró.
 *   Un trabajador no puede tocar los gastos de otro aunque sean de la misma obra.
 *
 * Laravel la encuentra sola por su nombre (App\Models\Gasto → App\Policies\GastoPolicy).
 * Los controladores la usan con `Gate::authorize('update', $gasto)`; si niega, la API
 * responde 403 con el mensaje de aquí.
 *
 * @package App\Policies
 */
class GastoPolicy
{
    /**
     * Lista completa de gastos (pantalla "Control y Auditoría de Gastos").
     */
    public function viewAny(User $user): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Solo el administrador puede consultar todos los gastos.');
    }

    /**
     * Corregir un gasto.
     */
    public function update(User $user, Gasto $gasto): Response
    {
        return $this->esAdminODueno($user, $gasto)
            ? Response::allow()
            : Response::deny('Solo puedes corregir los gastos que tú registraste.');
    }

    /**
     * Borrar un gasto (soft delete).
     */
    public function delete(User $user, Gasto $gasto): Response
    {
        return $this->esAdminODueno($user, $gasto)
            ? Response::allow()
            : Response::deny('Solo puedes borrar los gastos que tú registraste.');
    }

    /**
     * El Administrador, o quien registró el gasto.
     *
     * Se convierten a entero los dos ids: según el driver, la base de datos puede devolver
     * `user_id` como texto, y `"5" === 5` sería falso y le negaría el permiso al dueño.
     */
    private function esAdminODueno(User $user, Gasto $gasto): bool
    {
        return $user->isAdmin() || (int) $gasto->user_id === (int) $user->getKey();
    }
}
