<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Workflow\WorkflowController;

/**
 * Rutas del módulo de Administración de Flujos
 * 
 * Prefijo: /api/workflow
 * Middleware: auth:api
 */

Route::middleware(['auth:api'])->group(function () {
    
    // ========================================================================
    // MÓDULOS
    // ========================================================================
    Route::get('/modulos', [WorkflowController::class, 'listarModulos']);

    // ========================================================================
    // FLUJOS
    // ========================================================================
    Route::prefix('flujos')->group(function () {
        // Listar flujos
        Route::get('/', [WorkflowController::class, 'listarFlujos']);
        
        // Ver detalle
        Route::get('/{id}', [WorkflowController::class, 'verFlujo']);
        
        // Crear flujo
        Route::post('/', [WorkflowController::class, 'crearFlujo']);
        
        // Actualizar flujo
        Route::put('/{id}', [WorkflowController::class, 'actualizarFlujo']);
        
        // Eliminar flujo
        Route::delete('/{id}', [WorkflowController::class, 'eliminarFlujo']);

        // ====================================================================
        // PASOS DE UN FLUJO
        // ====================================================================
        Route::prefix('{idFlujo}/pasos')->group(function () {
            // Listar pasos
            Route::get('/', [WorkflowController::class, 'listarPasos']);
            
            // Agregar paso
            Route::post('/', [WorkflowController::class, 'agregarPaso']);
        });

        // ====================================================================
        // REGLAS DE UN FLUJO
        // ====================================================================
        Route::prefix('{idFlujo}/reglas')->group(function () {
            // Listar reglas
            Route::get('/', [WorkflowController::class, 'listarReglas']);
            
            // Agregar regla
            Route::post('/', [WorkflowController::class, 'agregarRegla']);
        });
    });

    // ========================================================================
    // PASOS (Operaciones individuales)
    // ========================================================================
    Route::prefix('pasos')->group(function () {
        // Actualizar paso
        Route::put('/{id}', [WorkflowController::class, 'actualizarPaso']);
        
        // Eliminar paso
        Route::delete('/{id}', [WorkflowController::class, 'eliminarPaso']);

        // ====================================================================
        // APROBADORES DE UN PASO
        // ====================================================================
        Route::prefix('{idPaso}/aprobadores')->group(function () {
            // Listar aprobadores
            Route::get('/', [WorkflowController::class, 'listarAprobadores']);
            
            // Agregar aprobador
            Route::post('/', [WorkflowController::class, 'agregarAprobador']);
        });
    });
});
