<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Turnos\PlantillaController;
use App\Http\Controllers\Turnos\GrupoController;
use App\Http\Controllers\Turnos\CuadroController;
use App\Http\Controllers\Turnos\AsignacionController;
use App\Http\Controllers\Turnos\NovedadController;

/**
 * Rutas del módulo Cuadro de Turnos
 *
 * Prefijo: /api/turnos
 * Middleware: auth:api, check.user.active
 */

Route::middleware(['auth:api'])->group(function () {

    // =========================================================================
    // PLANTILLAS DE TURNO
    // =========================================================================
    Route::apiResource('plantillas', PlantillaController::class);

    // =========================================================================
    // TIPOS DE NOVEDAD (catálogo)
    // =========================================================================
    Route::get('novedad-tipos', [PlantillaController::class, 'indexNovedadTipos']);
    Route::post('novedad-tipos', [PlantillaController::class, 'storeNovedadTipo']);
    Route::put('novedad-tipos/{id}', [PlantillaController::class, 'updateNovedadTipo']);

    // =========================================================================
    // GRUPOS
    // =========================================================================
    Route::apiResource('grupos', GrupoController::class);

    // Encargados del grupo
    Route::post('grupos/{id}/encargado', [GrupoController::class, 'asignarEncargado']);
    Route::get('grupos/{id}/encargado/historial', [GrupoController::class, 'historialEncargados']);

    // Empleados del grupo
    Route::get('grupos/{id}/empleados', [GrupoController::class, 'listarEmpleados']);
    Route::post('grupos/{id}/empleados', [GrupoController::class, 'agregarEmpleado']);
    Route::delete('grupos/{id}/empleados/{idEmpleado}', [GrupoController::class, 'retirarEmpleado']);

    // =========================================================================
    // CUADROS DE TURNO
    // =========================================================================
    Route::apiResource('cuadros', CuadroController::class)->except(['update']);

    // Transiciones de estado
    Route::post('cuadros/{id}/publicar', [CuadroController::class, 'publicar']);
    Route::post('cuadros/{id}/cerrar', [CuadroController::class, 'cerrar']);

    // Grilla y asignación masiva
    Route::get('cuadros/{id}/grilla', [CuadroController::class, 'grilla']);
    Route::post('cuadros/{id}/asignaciones', [CuadroController::class, 'asignarMasivo']);

    // =========================================================================
    // ASIGNACIONES INDIVIDUALES
    // =========================================================================
    Route::apiResource('asignaciones', AsignacionController::class)->except(['index']);

    // Turnos de un empleado en un período
    Route::get('empleados/{idEmpleado}/turnos', [AsignacionController::class, 'turnosEmpleado']);

    // =========================================================================
    // NOVEDADES
    // =========================================================================
    Route::apiResource('novedades', NovedadController::class);

    // Aprobación / rechazo
    Route::post('novedades/{id}/aprobar', [NovedadController::class, 'aprobar']);
    Route::post('novedades/{id}/rechazar', [NovedadController::class, 'rechazar']);
});