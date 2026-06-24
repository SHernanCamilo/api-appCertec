<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TalentoHumano\EventNovedadController;
use App\Http\Controllers\TalentoHumano\EventSolicitudController;

/**
 * Rutas del módulo de Eventos - Talento Humano
 * Prefijo: /api/talento-humano/eventos
 */

// ─── Solicitudes (event_horas_extra) ─────────────────────────────────────────
Route::prefix('solicitudes')->group(function () {
    Route::get('/',        [EventSolicitudController::class, 'index']);
    Route::get('/pendientes', [EventSolicitudController::class, 'pendientes']);
    Route::post('/',       [EventSolicitudController::class, 'store']);

    // Acciones de flujo
    Route::post('/{id}/aprobar',  [EventSolicitudController::class, 'aprobar']);
    Route::post('/{id}/rechazar', [EventSolicitudController::class, 'rechazar']);
    Route::get('/{id}/historial', [EventSolicitudController::class, 'historial']);

    Route::put('/{id}',    [EventSolicitudController::class, 'update']);
    Route::delete('/{id}', [EventSolicitudController::class, 'destroy']);
});

Route::get('/unidades-funcionales', [EventSolicitudController::class, 'unidadesFuncionales']);
Route::get('/unidades-funcionales/responsable', [EventSolicitudController::class, 'unidadesFuncionalesResponsable']);
Route::get('/empleados/mi-unidad', [EventSolicitudController::class, 'empleadosMiUnidad']);
Route::get('/flujo-preview', [EventSolicitudController::class, 'flujoPreview']);
Route::get('/flujos/catalogo', [EventSolicitudController::class, 'catalogoFlujos']);
Route::get('/flujos/configuracion-unidad', [EventSolicitudController::class, 'configuracionFlujoUnidad']);
Route::post('/flujos/configuracion-unidad', [EventSolicitudController::class, 'guardarConfiguracionFlujoUnidad']);

// ─── Catálogo de Novedades ────────────────────────────────────────────────────
Route::prefix('novedades')->group(function () {
    Route::get('/',        [EventNovedadController::class, 'index']);
    Route::post('/',       [EventNovedadController::class, 'store']);
    Route::get('/{id}',    [EventNovedadController::class, 'show']);
    Route::put('/{id}',    [EventNovedadController::class, 'update']);
    Route::delete('/{id}', [EventNovedadController::class, 'destroy']);
});

// ─── Vinculaciones Novedad ↔ Empresa / Cargo ──────────────────────────────────
Route::prefix('novedad-cargo')->group(function () {
    Route::get('/',        [EventNovedadController::class, 'vinculaciones']);
    Route::post('/',       [EventNovedadController::class, 'vincular']);
    Route::delete('/{id}', [EventNovedadController::class, 'desvincular']);
});

// ─── Consultas auxiliares ─────────────────────────────────────────────────────
Route::get('/novedades-aplicables', [EventNovedadController::class, 'novedadesAplicables']);
Route::get('/cargos',               [EventNovedadController::class, 'getCargos']);
