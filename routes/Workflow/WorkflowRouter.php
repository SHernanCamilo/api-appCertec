<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Workflow\WorkflowController;

/**
 * Rutas del Motor de Flujos
 *
 * Prefijo: /api/workflow  (aplicado desde api.php)
 * Middleware: auth:api, check.user.active
 */

// ============================================================================
// MÓDULOS
// ============================================================================
Route::get('/modulos', [WorkflowController::class, 'listarModulos']);

// ============================================================================
// DEFINICIONES (flujos) — nombres semánticos usados por el frontend
// ============================================================================
Route::prefix('definiciones')->group(function () {
    Route::get('/',        [WorkflowController::class, 'listarDefiniciones']);
    Route::post('/',       [WorkflowController::class, 'crearDefinicion']);
    Route::get('/{id}',    [WorkflowController::class, 'verDefinicion']);
    Route::put('/{id}',    [WorkflowController::class, 'actualizarDefinicion']);
    Route::delete('/{id}', [WorkflowController::class, 'eliminarDefinicion']);
    Route::patch('/{id}/toggle-estado', [WorkflowController::class, 'toggleEstadoDefinicion']);

    Route::get('/{id}/pasos',  [WorkflowController::class, 'listarPasos']);
    Route::get('/{id}/reglas', [WorkflowController::class, 'listarReglas']);
});

// ============================================================================
// FLUJOS — alias legacy (compatibilidad con /sistema/flujos)
// ============================================================================
Route::prefix('flujos')->group(function () {
    Route::get('/',        [WorkflowController::class, 'listarFlujos']);
    Route::post('/',       [WorkflowController::class, 'crearFlujo']);
    Route::get('/{id}',    [WorkflowController::class, 'verFlujo']);
    Route::put('/{id}',    [WorkflowController::class, 'actualizarFlujo']);
    Route::delete('/{id}', [WorkflowController::class, 'eliminarFlujo']);

    Route::get('/{idFlujo}/pasos',   [WorkflowController::class, 'listarPasos']);
    Route::post('/{idFlujo}/pasos',  [WorkflowController::class, 'agregarPaso']);
    Route::get('/{idFlujo}/reglas',  [WorkflowController::class, 'listarReglas']);
    Route::post('/{idFlujo}/reglas', [WorkflowController::class, 'agregarRegla']);
});

// ============================================================================
// PASOS (operaciones individuales)
// ============================================================================
Route::prefix('pasos')->group(function () {
    Route::post('/',       [WorkflowController::class, 'crearPaso']);
    Route::put('/{id}',    [WorkflowController::class, 'actualizarPaso']);
    Route::delete('/{id}', [WorkflowController::class, 'eliminarPaso']);

    Route::get('/{idPaso}/aprobadores',  [WorkflowController::class, 'listarAprobadores']);
    Route::post('/{idPaso}/aprobadores', [WorkflowController::class, 'agregarAprobador']);
});

// ============================================================================
// REGLAS (operaciones individuales)
// ============================================================================
Route::prefix('reglas')->group(function () {
    Route::post('/',       [WorkflowController::class, 'crearRegla']);
    Route::put('/{id}',    [WorkflowController::class, 'actualizarRegla']);
    Route::delete('/{id}', [WorkflowController::class, 'eliminarRegla']);
});

// ============================================================================
// APROBADORES (operaciones individuales)
// ============================================================================
Route::prefix('aprobadores')->group(function () {
    Route::post('/',       [WorkflowController::class, 'crearAprobador']);
    Route::put('/{id}',    [WorkflowController::class, 'actualizarAprobador']);
    Route::delete('/{id}', [WorkflowController::class, 'eliminarAprobador']);
});

// ============================================================================
// GRUPOS (WfGrupo)
// ============================================================================
Route::prefix('grupos')->group(function () {
    Route::get('/',            [WorkflowController::class, 'listarGrupos']);
    Route::post('/',           [WorkflowController::class, 'crearGrupo']);
    Route::get('/{id}',        [WorkflowController::class, 'verGrupo']);
    Route::put('/{id}',        [WorkflowController::class, 'actualizarGrupo']);
    Route::delete('/{id}',     [WorkflowController::class, 'eliminarGrupo']);
    Route::get('/{id}/cargos', [WorkflowController::class, 'listarCargosGrupo']);
});

// ============================================================================
// INSTANCIAS (monitoreo)
// ============================================================================
Route::prefix('instancias')->group(function () {
    Route::get('/',     [WorkflowController::class, 'listarInstancias']);
    Route::get('/{id}', [WorkflowController::class, 'verInstancia']);
});
