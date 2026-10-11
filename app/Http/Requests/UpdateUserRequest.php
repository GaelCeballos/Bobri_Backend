<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado para realizar esta petición.
     */
    public function authorize(): bool
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        
        /** @var User|null $targetUser */
        $targetUser = $this->route('usuario');

        if (!$currentUser || !$targetUser) {
            return false;
        }

        // 1. El Administrador puede actualizar a cualquier usuario
        if ($currentUser->isAdmin()) {
            return true;
        }

        // 2. Un Trabajador SOLO puede actualizar su propia cuenta
        return (int) $currentUser->id === (int) $targetUser->id;
    }

    /**
     * Reglas de validación.
     */
    public function rules(): array
    {
        $userId = $this->route('usuario')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            'rol' => ['sometimes', 'in:Administrador,Trabajador'],
            'estado' => ['sometimes', 'in:Activo,Inactivo'],
        ];
    }

    /**
     * Sanear datos validados para evitar escalación de privilegios de un Trabajador.
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();

        // Si NO es Administrador, prevenimos que modifique su rol o su estado
        if ($currentUser && !$currentUser->isAdmin()) {
            unset($validated['rol'], $validated['estado']);
        }

        return $validated;
    }
}