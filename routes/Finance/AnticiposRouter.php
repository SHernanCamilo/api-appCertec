<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Finance\AnticipoController;

/**
 * Rutas del módulo de Anticipos de Viaje
 * 
 * Prefijo: /api/anticipos
 * Middleware: auth:api
 */

Route::middleware(['auth:api'])->group(function () {
    
    // ========================================================================
    // CÁLCULO DE TOPES
    // ========================================================================
    Route::post('/calcular-topes', [AnticipoController::class, 'calcularTopes']);

    // ========================================================================
    // GESTIÓN DE SOLICITUDES
    // ========================================================================
    Route::prefix('solicitudes')->group(function () {
        Route::get('/', [AnticipoController::class, 'listar']);
        Route::post('/', [AnticipoController::class, 'crear']);
        Route::get('/{id}', [AnticipoController::class, 'ver']);
        
        // Fase aprobación
        Route::post('/{id}/aprobar', [AnticipoController::class, 'aprobar']);
        Route::post('/{id}/rechazar', [AnticipoController::class, 'rechazar']);
        Route::get('/{id}/historial', [AnticipoController::class, 'historial']);
        
        // Fase post-viaje
        Route::post('/{id}/desembolsar', [AnticipoController::class, 'desembolsar']);
        Route::post('/{id}/legalizar', [AnticipoController::class, 'legalizar']);
        Route::post('/{id}/decidir-contabilidad', [AnticipoController::class, 'decidirContabilidad']);
        Route::post('/{id}/registrar-devolucion', [AnticipoController::class, 'registrarDevolucion']);
        Route::post('/{id}/aprobar-excedente', [AnticipoController::class, 'aprobarExcedente']);
        Route::post('/{id}/rechazar-excedente', [AnticipoController::class, 'rechazarExcedente']);
        Route::post('/{id}/cerrar', [AnticipoController::class, 'cerrarSolicitud']);
    });

    // ========================================================================
    // CATÁLOGOS
    // ========================================================================
    Route::prefix('catalogos')->group(function () {
        // Tipos de anticipo
        Route::get('/tipos', [AnticipoController::class, 'tipos']);
        
        // Clases por tipo
        Route::get('/clases/{idTipo}', [AnticipoController::class, 'clases']);
        
        // Modalidades por clase
        Route::get('/modalidades/{idClase}', [AnticipoController::class, 'modalidades']);
        
        // Conceptos por modalidad
        Route::get('/conceptos/{idModalidad}', [AnticipoController::class, 'conceptos']);
        
        // Ciudades clasificadas
        Route::get('/ciudades', [AnticipoController::class, 'ciudades']);
    });
});
