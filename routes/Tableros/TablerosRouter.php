<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Tableros\TableroUrgenciasController;
use App\Http\Controllers\Tableros\TableroTokenController;

/**
 * Rutas de Tableros PRIVADAS (requieren auth:api).
 *
 * Prefijo ya aplicado: /api/tableros (definido en api.php)
 *
 * Las rutas públicas de SSE están en api.php directamente
 * bajo /api/public/tableros/urgencias/ para no pasar por auth.
 */

// Endpoint original (para el frontend Angular con sesión)
Route::get('/urgencias', [TableroUrgenciasController::class, 'index']);

// CRUD de dispositivos de tablero (admin)
Route::prefix('tokens')->group(function () {
    Route::get('/', [TableroTokenController::class, 'index']);
    // Sedes reales que trae la vista del tablero (alimenta el desplegable del
    // administrador). Va antes de las rutas con {id} para que no la capture.
    Route::get('/sedes', [TableroTokenController::class, 'sedes']);
    Route::post('/', [TableroTokenController::class, 'store']);
    Route::post('/{id}/regenerate-code', [TableroTokenController::class, 'regenerateCode']);
    Route::patch('/{id}/revoke', [TableroTokenController::class, 'revoke']);
    Route::patch('/{id}/activate', [TableroTokenController::class, 'activate']);
    Route::delete('/{id}', [TableroTokenController::class, 'destroy']);
});
