<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pruebas de la protección contra fuerza bruta de POST /api/auth/login.
 *
 * - 5 contraseñas incorrectas para el mismo correo desde la misma IP bloquean ese
 *   par 1 minuto (429 + Retry-After). Siempre 1 minuto: no es escalonado.
 * - Límite general de 20 intentos por minuto por IP, sin importar el correo.
 *
 * El limitador usa la caché 'array' en pruebas (phpunit.xml), que empieza vacía en
 * cada prueba: los intentos de una prueba no afectan a la siguiente.
 */
final class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Intento de login con contraseña incorrecta; mientras no haya bloqueo responde 401.
     *
     * @param string $email Correo con el que se intenta entrar
     * @return TestResponse
     */
    private function intentarFallido(string $email): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'mala'])
            ->assertUnauthorized();
    }

    /**
     * Durante el bloqueo ni la contraseña correcta entra, así el atacante no sabe cuándo acertó.
     */
    public function test_cinco_fallos_bloquean_el_login_un_minuto(): void
    {
        $user = User::factory()->create(['email' => 'obra@bobri.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->intentarFallido($user->email);
        }

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('status', 'error');
    }

    /**
     * El bloqueo siempre dura 1 minuto: un trabajador que olvidó su contraseña solo
     * espera ese tiempo, no tiene que pedirle al administrador que lo desbloquee.
     */
    public function test_el_bloqueo_termina_al_minuto(): void
    {
        $user = User::factory()->create(['email' => 'obra@bobri.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->intentarFallido($user->email);
        }

        $this->travel(61)->seconds();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $response->assertOk()
            ->assertJsonStructure(['access_token', 'user']);
    }

    /**
     * Entrar y salir varias veces o equivocarse de vez en cuando nunca bloquea.
     */
    public function test_un_login_correcto_borra_los_fallos_previos(): void
    {
        $user = User::factory()->create(['email' => 'obra@bobri.test']);

        for ($i = 0; $i < 4; $i++) {
            $this->intentarFallido($user->email);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->intentarFallido($user->email);
        }

        // Van 8 fallos en total: si el login correcto no los hubiera borrado, el 5.º ya
        // habría bloqueado y esta petición respondería 429
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    /**
     * El bloqueo es por correo + IP, no solo por IP: en la obra varios trabajadores
     * comparten la red y los errores de uno no deben dejar fuera a los demás.
     */
    public function test_el_bloqueo_no_afecta_a_otro_correo_de_la_misma_ip(): void
    {
        $user1 = User::factory()->create(['email' => 'obra@bobri.test']);
        $user2 = User::factory()->create(['email' => 'cuadrilla@bobri.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->intentarFallido($user1->email);
        }

        $response = $this->postJson('/api/auth/login', ['email' => $user2->email, 'password' => 'password']);
        $response->assertOk();
    }

    /**
     * El correo se cuenta en minúsculas: escribirlo con otras mayúsculas no reinicia
     * el contador ni sirve para seguir probando contraseñas.
     */
    public function test_mayusculas_en_el_correo_no_saltan_el_bloqueo(): void
    {
        $user = User::factory()->create(['email' => 'obra@bobri.test']);

        for ($i = 0; $i < 5; $i++) {
            if ($i % 2 === 0) {
                $this->intentarFallido($user->email);
            } else {
                $this->intentarFallido(strtoupper($user->email));
            }
        }

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password']);
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('status', 'error');
    }

    /**
     * El límite por IP frena a quien prueba muchos correos distintos desde la misma
     * máquina (el bloqueo por correo + IP no lo detectaría).
     */
    public function test_limite_general_de_20_intentos_por_minuto_por_ip(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->intentarFallido("nadie{$i}@bobri.test");
        }

        $response = $this->postJson('/api/auth/login', ['email' => 'nadie21@bobri.test', 'password' => 'mala']);
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('status', 'error');
    }
}
