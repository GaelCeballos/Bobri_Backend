<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ConceptoController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\ObraController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Públicas
|--------------------------------------------------------------------------
*/
// Límite general por IP: frena a quien prueba muchos correos distintos desde la misma
// máquina. Es holgado (20/min) para que una cuadrilla en la misma red entre sin problema;
// el bloqueo de 1 min por correo + IP está en AuthController::login
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

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

    // El trabajador recibe solo las obras activas y sin importe (ver ObraController::index)
    Route::get('/obras', [ObraController::class, 'index']);

    // Gastos: los permisos por gasto los decide GastoPolicy (lista completa solo admin;
    // corregir y borrar, el admin o quien lo registró)
    Route::get('/gastos', [GastoController::class, 'index']);
    Route::get('/gastos/mios', [GastoController::class, 'mios']);
    Route::post('/gastos', [GastoController::class, 'store']);
    Route::patch('/gastos/{gasto}', [GastoController::class, 'update']);
    Route::delete('/gastos/{gasto}', [GastoController::class, 'destroy']);
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

    // Obras: sin DELETE, al terminar se marcan Finalizada (los gastos conservan su obra)
    Route::get('obras/{obra}', [ObraController::class, 'show']);
    Route::post('obras', [ObraController::class, 'store']);
    Route::patch('obras/{obra}', [ObraController::class, 'update']);
});