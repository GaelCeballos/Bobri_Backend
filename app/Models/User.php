<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * Usuario del sistema.
 *
 * Roles: Administrador (acceso total al panel) o Trabajador (solo captura de gastos).
 *
 * Sesión única: `current_token` guarda el único JWT válido del usuario. Cada
 * login nuevo invalida el anterior y el middleware CheckRole rechaza con
 * 403 SESSION_REPLACED cualquier petición hecha con un token distinto.
 *
 * @package App\Models
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password Hash; nunca se expone
 * @property string|null $remember_token
 * @property string $rol 'Administrador' | 'Trabajador'
 * @property string $estado 'Activo' | 'Inactivo'
 * @property string|null $current_token JWT de la única sesión válida; nunca se expone
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Campos asignables con create()/update().
     *
     * - `rol` y `estado` los asigna el administrador desde UserController
     *   (validados en StoreUserRequest / UpdateUserRequest).
     * - `current_token` solo lo escribe AuthController (login, refresh, logout);
     *   ninguna regla de validación lo acepta, así que nunca llega de una petición.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'estado',
        'current_token',
    ];

    /**
     * Campos que nunca se incluyen en las respuestas JSON.
     *
     * `current_token` es un JWT vivo: si apareciera en /auth/me o /usuarios,
     * quien lo viera podría suplantar la sesión de ese usuario.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'current_token',
    ];

    /**
     * Conversiones de tipo de los atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Al asignar una contraseña en texto plano, Laravel la guarda como hash
            'password' => 'hashed',
        ];
    }

    /**
     * Identificador que se guarda en el claim `sub` del JWT (requerido por JWTSubject).
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Claims extra del JWT (requerido por JWTSubject).
     *
     * El rol viaja en el token solo como dato informativo para el front; la
     * autorización real la hace CheckRole leyendo la base de datos. No se incluye
     * `estado` porque quedaría desactualizado si el admin desactiva al usuario
     * mientras el token sigue vivo.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'rol' => $this->rol,
        ];
    }

    /**
     * ¿Tiene acceso total al panel?
     */
    public function isAdmin(): bool
    {
        return $this->rol === 'Administrador';
    }

    /**
     * ¿Solo puede capturar gastos?
     */
    public function isTrabajador(): bool
    {
        return $this->rol === 'Trabajador';
    }

    /**
     * ¿Puede iniciar sesión y usar la API? (lo revisan AuthController y CheckRole)
     */
    public function isActive(): bool
    {
        return $this->estado === 'Activo';
    }
}
