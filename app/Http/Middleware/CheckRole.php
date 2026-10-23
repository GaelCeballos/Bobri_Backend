<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth('api')->user();

        if (!$user){
            return response()->json([
                'status'=> 'error',
                'message'=> 'No autorizado. Token inválido o expirado.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        //1. Validar que el usuario este activo
        if (method_exists($user, 'isActive') && !$user->isActive()) {
            $this->invalidateCurrentToken();

            return response()->json([
                'status'=> 'error',
                'code' => 'USER_INACTIVE',
                'message'=> 'Su cuenta ha sido desactivada por el administrador.'
            ],Response::HTTP_FORBIDDEN);
        }

        //2. Reemplazo automatico de sesion (Single Session)
        $currentToken = JWTAuth::getToken() ? (string) JWTAuth::getToken() : null;
        if ($user->current_token && $currentToken !== $user->current_token){
            $this->invalidateCurrentToken();

            return response()->json([
                'status' => 'error',
                'code' => 'SESSION_REPLACED',
                'message' => 'Se ha iniciado sesión en otro dispositivo. Vuelva a ingresar'
            ], Response::HTTP_FORBIDDEN);
        }

        //3. Validar el rol del usuario
        if (!empty($roles) && !in_array($user->rol, $roles, true)){
            return response()->json([
                'status' => 'error',
                'message' => 'Acceso denegado. Permisos insuficientes.'
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function invalidateCurrentToken(): void
    {
        try{
            JWTAuth::parseToken()->invalidate();
        } catch (\Exception $e){
            //Token previamente expirado o destruido
        }
    }
}
