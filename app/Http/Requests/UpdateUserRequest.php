<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Función pública que determina si el usuario está autorizado para 
     * realizar esta solicitud.
     * 
     * @return bool
     */
    public function authorize(): bool
    {
        return auth('api')->user()?->isAdmin ?? false;
    }

    /**
     * Función pública que devuelve las reglas de validación para 
     * la solicitud de actualización de usuario.
     * 
     * @return array<string, ValidationRule|array<mixed>|string>
     * 
     */
    public function rules(): array
    {
        $userId = $this->route('usuario')?->id;
        
        return [
           'name' => ['sometimes','string', 'max:255'],
           'email' => ['sometimes', 'string', 'email','max:255', Rule::unique('users')->ignore($userId)],
           'password' => ['nullable', 'string', 'min:8'],
           'rol' => ['sometimes', 'in:Administrador,Trabajador'],
           'estado' => ['sometimes', 'in:Activo,Inactivo'],
        ];
    }
}
