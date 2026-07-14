<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fabric\BiGrupoController;
use App\Http\Controllers\Fabric\BiVistaController;
use App\Http\Controllers\Fabric\BiDelegacionController;

/**
 * Parámetros BI — Esquemas (bi_grupos) y vistas (bi_vistas)
 *
 * Prefijo: /api/fabric
 */
Route::prefix('bi-grupos')->group(function () {
    Route::get('/buscar', [BiGrupoController::class, 'buscar']);
    Route::get('/catalogo-fabric', [BiGrupoController::class, 'catalogoFabric']);
    Route::post('/{id}/sincronizar-vistas', [BiGrupoController::class, 'sincronizarVistasFabric']);
    Route::get('/{id}/delegaciones', [BiDelegacionController::class, 'show']);
    Route::put('/{id}/delegaciones', [BiDelegacionController::class, 'update']);
    Route::get('/{id}/delegaciones-usuarios', [BiDelegacionController::class, 'showUsuario']);
    Route::put('/{id}/delegaciones-usuarios', [BiDelegacionController::class, 'updateUsuario']);
    Route::get('/', [BiGrupoController::class, 'index']);
    Route::post('/', [BiGrupoController::class, 'store']);
    Route::get('/{id}', [BiGrupoController::class, 'show']);
    Route::put('/{id}', [BiGrupoController::class, 'update']);
    Route::delete('/{id}', [BiGrupoController::class, 'destroy']);
});

Route::prefix('bi-vistas')->group(function () {
    Route::get('/departamentos-catalogo', [BiVistaController::class, 'catalogoDepartamentos']);
    Route::get('/', [BiVistaController::class, 'index']);
    Route::post('/', [BiVistaController::class, 'store']);
    Route::post('/bulk', [BiVistaController::class, 'storeBulk']);
    Route::put('/{id}', [BiVistaController::class, 'update']);
    Route::delete('/{id}', [BiVistaController::class, 'destroy']);
});

Route::prefix('bi-usuarios')->group(function () {
    Route::get('/auditoria/esquemas', [\App\Http\Controllers\Fabric\BiUsuarioController::class, 'auditoriaEsquemas']);
    Route::get('/auditoria', [\App\Http\Controllers\Fabric\BiUsuarioController::class, 'auditoria']);
    Route::get('/{userId}/permisos', [\App\Http\Controllers\Fabric\BiUsuarioController::class, 'permisos']);
});
