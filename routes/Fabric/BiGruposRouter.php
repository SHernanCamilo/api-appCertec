<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fabric\BiGrupoController;
use App\Http\Controllers\Fabric\BiVistaController;

/**
 * Parámetros BI — Esquemas (bi_grupos) y vistas (bi_vistas)
 *
 * Prefijo: /api/fabric
 */
Route::prefix('bi-grupos')->group(function () {
    Route::get('/buscar', [BiGrupoController::class, 'buscar']);
    Route::get('/catalogo-fabric', [BiGrupoController::class, 'catalogoFabric']);
    Route::post('/{id}/sincronizar-vistas', [BiGrupoController::class, 'sincronizarVistasFabric']);
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
