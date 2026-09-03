<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TalentoHumano\EventNovedadController;
use App\Http\Controllers\TalentoHumano\EventSolicitudController;
use App\Http\Controllers\TalentoHumano\WfGrupoUfController;

/**
 * Rutas del módulo de Eventos - Talento Humano
 * Prefijo: /api/talento-humano/eventos
 */

// ─── Solicitudes (event_horas_extra) ─────────────────────────────────────────
Route::prefix('solicitudes')->group(function () {
    Route::get('/',        [EventSolicitudController::class, 'index']);
    Route::get('/pendientes', [EventSolicitudController::class, 'pendientes']);
    Route::get('/digitalizados', [EventSolicitudController::class, 'digitalizados']);
    Route::get('/gestionados', [EventSolicitudController::class, 'gestionados']);
    Route::get('/solapamiento', [EventSolicitudController::class, 'solapamiento']);
    Route::post('/',       [EventSolicitudController::class, 'store']);

    // Acciones de flujo
    Route::post('/digitalizar-masivo', [EventSolicitudController::class, 'digitalizarMasivo']);
    Route::post('/{id}/aprobar',  [EventSolicitudController::class, 'aprobar']);
    Route::post('/{id}/rechazar', [EventSolicitudController::class, 'rechazar']);
    Route::post('/{id}/digitalizar', [EventSolicitudController::class, 'digitalizar']);
    Route::get('/{id}/historial', [EventSolicitudController::class, 'historial']);

    Route::get('/{id}',    [EventSolicitudController::class, 'show']);
    Route::put('/{id}',    [EventSolicitudController::class, 'update']);
    Route::delete('/{id}', [EventSolicitudController::class, 'destroy']);
});

Route::get('/unidades-funcionales', [EventSolicitudController::class, 'unidadesFuncionales']);
Route::get('/unidades-funcionales/responsable', [EventSolicitudController::class, 'unidadesFuncionalesResponsable']);
Route::get('/empleados/mi-unidad', [EventSolicitudController::class, 'empleadosMiUnidad']);
Route::get('/flujo-preview', [EventSolicitudController::class, 'flujoPreview']);
Route::get('/motivos-rechazo', [EventSolicitudController::class, 'motivosRechazo']);
Route::get('/flujos/catalogo', [EventSolicitudController::class, 'catalogoFlujos']);
Route::get('/flujos/configuracion-unidad', [EventSolicitudController::class, 'configuracionFlujoUnidad']);
Route::post('/flujos/configuracion-unidad', [EventSolicitudController::class, 'guardarConfiguracionFlujoUnidad']);

// ─── Grupos de UF (motor de reglas / flujos) ─────────────────────────────────
Route::prefix('grupos')->group(function () {
    Route::get('/',           [WfGrupoUfController::class, 'index']);
    Route::get('/{id}',       [WfGrupoUfController::class, 'show']);
    Route::post('/',          [WfGrupoUfController::class, 'store']);
    Route::put('/{id}',       [WfGrupoUfController::class, 'update']);
    Route::post('/{id}/flujo',[WfGrupoUfController::class, 'asignarFlujo']);
    Route::delete('/{id}',    [WfGrupoUfController::class, 'destroy']);
});

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

