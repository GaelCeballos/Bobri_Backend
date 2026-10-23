<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Clase base de todas las pruebas Feature (las que hacen peticiones HTTP a la API).
 *
 * Agrega {@see comoUsuario()} para que las pruebas se autentiquen igual que el
 * front: con un JWT en la cabecera `Authorization`, pasando por el middleware
 * real `auth:api` + `CheckRole` (sesión única, usuario activo y rol).
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Hace que las siguientes peticiones de la prueba se envíen como `$usuario`.
     *
     * Hace lo mismo que el login real (AuthController::login) sin pasar por la
     * contraseña:
     *  1. Genera un JWT para el usuario.
     *  2. Lo guarda en `users.current_token`. Sin este paso CheckRole respondería
     *     403 SESSION_REPLACED, porque la sesión única solo acepta ese token.
     *  3. Envía `Authorization: Bearer <token>` y `Accept: application/json`; sin
     *     el Accept, Laravel respondería con redirecciones/HTML en vez de 401/422.
     *
     * Uso:  $this->comoUsuario($admin)->getJson('/api/usuarios')->assertOk();
     *
     * @param  User  $usuario  Usuario ya guardado en la base de datos.
     * @return static Para encadenar la petición.
     */
    protected function comoUsuario(User $usuario): static
    {
        $token = JWTAuth::fromUser($usuario);
        $usuario->update(['current_token' => $token]);

        return $this->conToken($token);
    }

    /**
     * Hace que las siguientes peticiones se envíen con un JWT concreto, aunque ya
     * no sea el vigente. Sirve para probar 403 SESSION_REPLACED con un token viejo.
     *
     * En producción cada petición es un proceso nuevo; en las pruebas todas las
     * peticiones de un mismo método comparten la aplicación, y hay DOS objetos que
     * recuerdan el token de la petición anterior:
     *  - `tymon.jwt`: lo usa el guard `auth:api` para saber quién es el usuario.
     *  - la fachada `JWTAuth` (`tymon.jwt.auth`): la usa CheckRole para comparar
     *    con `current_token`.
     * Sin fijarles el token nuevo a los dos, al cambiar de usuario la petición se
     * atendería como el usuario anterior y CheckRole respondería SESSION_REPLACED.
     *
     * Para probar una petición SIN token, hazla en un método de prueba aparte
     * (cada método arranca con la aplicación limpia).
     *
     * @param  string  $token  JWT a enviar en `Authorization: Bearer`.
     * @return static Para encadenar la petición.
     */
    protected function conToken(string $token): static
    {
        // El guard también recuerda al usuario ya autenticado: se descarta para crear uno nuevo
        $this->app['auth']->forgetGuards();
        $this->app['tymon.jwt']->setToken($token);
        JWTAuth::setToken($token);

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }
}
