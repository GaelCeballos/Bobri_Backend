<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
        'estado',
        'current_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'current_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Métodos requeridos por JWTSubject para generar el token JWT.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'rol' => $this->rol,
            'estado' => $this->estado,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->rol === 'Administrador';
    }

    public function isTrabajador(): bool
    {
        return $this->rol === 'Trabajador';
    }

    public function isActive(): bool
    {
        return $this->estado === 'Activo';
    }
}
