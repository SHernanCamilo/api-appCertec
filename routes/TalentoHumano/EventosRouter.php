<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TalentoHumano\EventNovedadController;

/**
 * Rutas del módulo de Eventos - Talento Humano
 *
 * Prefijo: /api/talento-humano/eventos
 * Middleware: auth:api, check.user.active
 */

// ─── Catálogo de Novedades ────────────────────────────────────────────────────
Route::prefix('novedades')->group(function () {
    Route::get('/',    [EventNovedadController::class, 'index']);
    Route::post('/',   [EventNovedadController::class, 'store']);
    Route::get('/{id}',    [EventNovedadController::class, 'show']);
    Route::put('/{id}',    [EventNovedadController::class, 'update']);
    Route::delete('/{id}', [EventNovedadController::class, 'destroy']);
});

// ─── Vinculaciones Novedad ↔ Empresa / Cargo ──────────────────────────────────
Route::prefix('novedad-cargo')->group(function () {
    Route::get('/',         [EventNovedadController::class, 'vinculaciones']);
    Route::post('/',        [EventNovedadController::class, 'vincular']);
    Route::delete('/{id}',  [EventNovedadController::class, 'desvincular']);
});

// ─── Consulta de novedades aplicables para un empleado ───────────────────────
Route::get('/novedades-aplicables', [EventNovedadController::class, 'novedadesAplicables']);

// ─── Catálogos auxiliares ─────────────────────────────────────────────────────
Route::get('/cargos', [EventNovedadController::class, 'getCargos']);
