<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fábrica de usuarios para las pruebas.
 *
 * Crea usuarios con datos falsos pero válidos, para que cada prueba arme en una
 * línea el usuario que necesita en lugar de depender de los del seeder:
 *
 *   User::factory()->admin()->create();                  // Administrador activo
 *   User::factory()->create();                           // Trabajador activo
 *   User::factory()->trabajador()->inactivo()->create(); // Trabajador desactivado
 *
 * Por defecto crea un Trabajador Activo: es el usuario con menos permisos, así
 * una prueba nunca obtiene acceso de administrador sin pedirlo explícitamente.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Hash de la contraseña, calculado una sola vez y reutilizado: generar un hash
     * bcrypt por cada usuario haría lentas las pruebas.
     */
    protected static ?string $password;

    /**
     * Valores por defecto de cada usuario creado.
     *
     * La contraseña de todos es "password".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'rol' => 'Trabajador',
            'estado' => 'Activo',
        ];
    }

    /**
     * Usuario con acceso total al panel (puede corregir y fusionar catálogos).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol' => 'Administrador',
        ]);
    }

    /**
     * Usuario que solo captura gastos. Es el valor por defecto; existe para que la
     * prueba lo diga explícitamente cuando el rol es lo que se está probando.
     */
    public function trabajador(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rol' => 'Trabajador',
        ]);
    }

    /**
     * Usuario desactivado por el administrador: el middleware CheckRole debe
     * rechazarlo con 403 USER_INACTIVE aunque tenga un token válido.
     */
    public function inactivo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'estado' => 'Inactivo',
        ]);
    }

    /**
     * Usuario con el correo sin verificar (estado original de Laravel; Bobri no
     * verifica correos, se conserva por compatibilidad).
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
