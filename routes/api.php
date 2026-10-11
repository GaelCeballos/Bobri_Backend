<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Públicas de Autenticación
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Rutas Protegidas (Requieren JWT válido y Estado Activo)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'check.role'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
});

/*
|--------------------------------------------------------------------------
| Rutas Exclusivas para el Administrador
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'check.role:Administrador'])->group(function () {
    Route::apiResource('usuarios', UserController::class);
    Route::patch('usuarios/{usuario}/toggle-state', [UserController::class, 'toggleState']);
});p