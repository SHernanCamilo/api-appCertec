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
        // Listar solicitudes
        Route::get('/', [AnticipoController::class, 'listar']);
        
        // Crear solicitud
        Route::post('/', [AnticipoController::class, 'crear']);
        
        // Ver detalle
        Route::get('/{id}', [AnticipoController::class, 'ver']);
        
        // Aprobar solicitud
        Route::post('/{id}/aprobar', [AnticipoController::class, 'aprobar']);
        
        // Rechazar solicitud
        Route::post('/{id}/rechazar', [AnticipoController::class, 'rechazar']);
        
        // Historial de aprobaciones
        Route::get('/{id}/historial', [AnticipoController::class, 'historial']);
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
