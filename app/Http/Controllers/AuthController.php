<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
     * Petición para iniciar sesión.
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!$token = JWTAuth::attempt($credentials)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Credenciales incorrectas',
            ], Response::HTTP_UNAUTHORIZED);
        }

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