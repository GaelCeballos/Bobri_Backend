<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Crea y configura la aplicación de Laravel.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'check.role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Sin token, `auth:api` intenta redirigir a una pantalla de login (que la API no
        // tiene) y respondía una página HTML de error. Con null no redirige: lanza
        // AuthenticationException y abajo se responde 401 en JSON
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Errores de la API en español y con el mismo formato que el resto de las
         * respuestas: { status: 'error', message }.
         *
         * - Las rutas /api/* SIEMPRE responden JSON, aunque el cliente olvide la
         *   cabecera `Accept: application/json`.
         * - Los mensajes no revelan detalles internos (p. ej. el 404 de Laravel
         *   incluía el nombre de la clase "App\Models\Concepto").
         * - Los 422 de validación conservan el formato estándar de Laravel
         *   { message, errors } que el front ya usa para marcar cada campo.
         */
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $error = fn (string $mensaje, int $codigo) => response()->json([
            'status' => 'error',
            'message' => $mensaje,
        ], $codigo);

        // Token ausente, inválido o vencido. El front limpia la sesión y manda al login
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? $error('No autenticado. Inicia sesión de nuevo.', 401)
            : null);

        // Ruta inexistente o registro inexistente (Laravel convierte ModelNotFoundException en este)
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? $error('El recurso solicitado no existe.', 404)
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $request->is('api/*')
            ? $error('Esta operación no está permitida en esta ruta.', 405)
            : null);

        // Límite de peticiones superado (middleware throttle)
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $request->is('api/*')
            ? $error('Demasiados intentos. Espera un momento y vuelve a intentarlo.', 429)
            : null);

        // Cualquier otro error inesperado (500). Solo con APP_DEBUG=false: en desarrollo
        // se deja el detalle de Laravel para poder depurar. Se excluyen los errores HTTP
        // con su propio código (403, 409…) y los de validación (422)
        $exceptions->render(function (Throwable $e, Request $request) use ($error) {
            $esperado = $e instanceof HttpExceptionInterface || $e instanceof ValidationException;

            return $request->is('api/*') && ! $esperado && ! config('app.debug')
                ? $error('Ocurrió un error en el servidor. Intenta de nuevo más tarde.', 500)
                : null;
        });
    })->create();
