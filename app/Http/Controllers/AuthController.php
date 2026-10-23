<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador que se encarga de la autenticacion de usurios 
 * y la gestion de tokens JWT.
 * 
 * @package App\Http\Controllers
 */
class AuthController extends Controller
{
    /**
     * Fallos de contraseña permitidos antes de bloquear (por correo + IP).
     */
    private const MAX_FALLOS = 5;

    /**
     * Cuánto recuerda los fallos: los errores de dedo de hace más de 15 min se olvidan.
     */
    private const VENTANA_FALLOS_SEGUNDOS = 15 * 60;

    /**
     * Duración del bloqueo. Siempre la misma (no escalonado): basta para frenar la
     * fuerza bruta y un trabajador que olvidó su contraseña nunca espera más de 1 min.
     */
    private const BLOQUEO_SEGUNDOS = 60;

    /**
     * Petición para iniciar sesión.
     *
     * Protección contra fuerza bruta: tras 5 contraseñas incorrectas para el mismo
     * correo desde la misma IP, ese par queda bloqueado 1 minuto (429 + Retry-After).
     * Solo cuentan los fallos y un login correcto los borra, así que entrar y salir
     * varias veces nunca bloquea. Se agrupa por correo + IP (no solo IP) para que los
     * errores de un trabajador no bloqueen a toda la cuadrilla que comparte la red,
     * ni un atacante desde otra IP deje fuera al dueño de la cuenta.
     * El límite general por IP (probar muchos correos) está en routes/api.php.
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $llaveFallos = $this->llaveLogin($request, 'fallos');
        $llaveBloqueo = $this->llaveLogin($request, 'bloqueo');

        // Se revisa ANTES de comprobar la contraseña: durante el bloqueo ni siquiera
        // una contraseña correcta entra, si no el atacante sabría cuándo acertó
        if (RateLimiter::tooManyAttempts($llaveBloqueo, 1)) {
            $segundos = RateLimiter::availableIn($llaveBloqueo);

            return response()->json([
                'status' => 'error',
                'message' => "Demasiados intentos fallidos. Espera {$segundos} segundos e intenta de nuevo.",
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => $segundos]);
        }

        if (!$token = JWTAuth::attempt($credentials)) {
            RateLimiter::hit($llaveFallos, self::VENTANA_FALLOS_SEGUNDOS);

            // El bloqueo es una llave aparte para que dure 1 min contados desde el
            // 5.º fallo (con una sola llave contaría desde el 1.º y podría durar menos)
            if (RateLimiter::attempts($llaveFallos) >= self::MAX_FALLOS) {
                RateLimiter::clear($llaveFallos);
                RateLimiter::hit($llaveBloqueo, self::BLOQUEO_SEGUNDOS);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Credenciales incorrectas',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Contraseña correcta: se olvidan los fallos previos
        RateLimiter::clear($llaveFallos);

        /** @var User|null $user */
        $user = JWTAuth::user();

        if ($user && !$user->isActive()) {
            /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
            $guard = auth('api');
            $guard->logout();

            return response()->json([
                'status' => 'error',
                'message' => 'El usuario se encuentra inactivo.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Invalida el token previo si el usuario ya tenía una sesión abierta
        if ($user && !empty($user->current_token)) {
            try {
                JWTAuth::setToken($user->current_token)->invalidate();
            } catch (\Exception $e) {
                // Token expirado o previamente destruido
            }
        }

        // Se guarda el token nuevo como la única sesión válida del usuario
        $user?->update(['current_token' => $token]);

        return $this->respondWithToken($token, $user);
    }

    /**
     * Petición para obtener la información del usuario autenticado.
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => auth('api')->user(),
        ]);
    }

    /**
     * Petición para cerrar sesión.
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        /** @var User|null $user */
        $user = auth('api')->user();
        $token = JWTAuth::getToken();

        if ($token) {
            try {
                JWTAuth::invalidate($token);
            } catch (\Exception $e) {
                // Token inválido o ya expirado.
            }
        }

        if ($user) {
            $user->update(['current_token' => null]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Sesion cerrada correctamente.'
        ]);
    }

    /**
     * Petición para refrescar el token de autenticación.
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        /** @var User|null $user */
        $user = auth('api')->user();

        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');
        $newToken = $guard->refresh();

        if ($user) {
            $user->update(['current_token' => $newToken]);
        }

        return $this->respondWithToken($newToken, $user);
    }

    /**
     * Llave del limitador de intentos de login para el par correo + IP.
     *
     * El correo va en minúsculas y transliterado para que "Admin@Gmail.com" y
     * "admin@gmail.com" compartan contador y no sirvan para saltarse el bloqueo.
     * @param Request $request
     * @param string $tipo 'fallos' o 'bloqueo'
     * @return string
     */
    private function llaveLogin(Request $request, string $tipo): string
    {
        return "login-{$tipo}:".Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip());
    }

    /**
     * Responde con el token de autenticación y los datos del usuario.
     *
     * El usuario se recibe como parámetro porque, justo después de
     * `JWTAuth::attempt()` en el login, `auth('api')->user()` todavía está vacío
     * (el guard no conoce el token recién creado) y la respuesta llevaba
     * `"user": null`. El front necesita ese usuario para saber su rol.
     *
     * @param string $token JWT recién emitido
     * @param User|null $user Dueño del token
     * @return JsonResponse
     */
    protected function respondWithToken(string $token, ?User $user): JsonResponse
    {
        /** @var \PHPOpenSourceSaver\JWTAuth\JWTGuard $guard */
        $guard = auth('api');

        return response()->json([
            'status' => 'success',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $guard->factory()->getTTL() * 60,
            'user' => $user,
        ]);
    }
}