<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ConceptoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Públicas
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Rutas Protegidas por Autenticación y Estado Activo
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'check.role'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Catálogos que alimentan ambos roles al capturar gastos: consultar y crear
    Route::get('/categorias', [CategoriaController::class, 'index']);
    Route::post('/categorias', [CategoriaController::class, 'store']);
    Route::get('/conceptos', [ConceptoController::class, 'index']);
    Route::post('/conceptos', [ConceptoController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Rutas Exclusivas para el Administrador
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'check.role:Administrador'])->group(function () {
    // Sin destroy: un usuario no se borra, se desactiva con toggle-state. Así sus gastos y la
    // bitácora conservan quién hizo cada cosa (además, gastos.user_id impide borrarlo)
    Route::apiResource('usuarios', UserController::class)->except('destroy');
    Route::patch('usuarios/{usuario}/toggle-state', [UserController::class, 'toggleState']);

    // Reporte de conceptos que parecen el mismo (para revisarlos y fusionarlos)
    Route::get('conceptos/posibles-duplicados', [ConceptoController::class, 'posiblesDuplicados']);

    // Corrección de catálogos (renombrar, cambiar categoría o unidad)
    Route::patch('categorias/{categoria}', [CategoriaController::class, 'update']);
    Route::patch('conceptos/{concepto}', [ConceptoController::class, 'update']);
    // Une un concepto duplicado con otro: mueve sus gastos y lo elimina
    Route::post('conceptos/{concepto}/fusionar', [ConceptoController::class, 'fusionar']);
});