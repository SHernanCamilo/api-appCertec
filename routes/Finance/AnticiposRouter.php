<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Finance\AnticipoController;
use App\Http\Controllers\Finance\AnticipoConceptoController;

/**
 * Rutas del módulo de Anticipos de Viaje
 *
 * Prefijo: /api/anticipos  (aplicado desde api.php)
 * Middleware: auth:api, check.user.active
 */

// ============================================================================
// CÁLCULO DE TOPES
// ============================================================================
Route::post('/calcular-topes', [AnticipoController::class, 'calcularTopes']);

// ============================================================================
// GESTIÓN DE SOLICITUDES
// ============================================================================
Route::prefix('solicitudes')->group(function () {
    Route::get('/',     [AnticipoController::class, 'listar']);
    Route::post('/',    [AnticipoController::class, 'crear']);
    Route::get('/{id}', [AnticipoController::class, 'ver']);

    // Fase aprobación
    Route::post('/{id}/aprobar',  [AnticipoController::class, 'aprobar']);
    Route::post('/{id}/rechazar', [AnticipoController::class, 'rechazar']);
    Route::get('/{id}/historial', [AnticipoController::class, 'historial']);

    // Documentos / Soportes
    Route::post('/{id}/documentos', [AnticipoController::class, 'subirDocumento']);
    Route::get('/{id}/documentos',  [AnticipoController::class, 'listarDocumentos']);

    // Fase post-viaje
    Route::post('/{id}/desembolsar',          [AnticipoController::class, 'desembolsar']);
    Route::post('/{id}/legalizar',            [AnticipoController::class, 'legalizar']);
    Route::post('/{id}/decidir-contabilidad', [AnticipoController::class, 'decidirContabilidad']);
    Route::post('/{id}/registrar-devolucion', [AnticipoController::class, 'registrarDevolucion']);
    Route::post('/{id}/aprobar-excedente',    [AnticipoController::class, 'aprobarExcedente']);
    Route::post('/{id}/rechazar-excedente',   [AnticipoController::class, 'rechazarExcedente']);
    Route::post('/{id}/cerrar',               [AnticipoController::class, 'cerrarSolicitud']);
});

// ============================================================================
// DOCUMENTOS (por ID de documento)
// ============================================================================
Route::prefix('documentos')->group(function () {
    Route::get('/{idDocumento}/descargar', [AnticipoController::class, 'descargarDocumento']);
    Route::delete('/{idDocumento}',        [AnticipoController::class, 'eliminarDocumento']);
});

// ============================================================================
// CATÁLOGOS — Tipos, Clases, Modalidades, Ciudades (lectura rápida)
// ============================================================================
Route::prefix('catalogos')->group(function () {
    // Lectura simple (AnticipoController) — compatibilidad con vistas actuales
    Route::get('/tipos',                 [AnticipoController::class, 'tipos']);
    Route::get('/clases/{idTipo}',       [AnticipoController::class, 'clases']);
    Route::get('/modalidades/{idClase}', [AnticipoController::class, 'modalidades']);
    Route::get('/conceptos/{idModalidad}', [AnticipoController::class, 'conceptos']);
    Route::get('/ciudades',              [AnticipoController::class, 'ciudades']);

    // Catálogos enriquecidos (AnticipoConceptoController) — usados por Parámetros
    Route::get('/anti-tipos',                 [AnticipoConceptoController::class, 'getTipos']);
    Route::get('/anti-clases/{tipoId}',       [AnticipoConceptoController::class, 'getClasesPorTipo']);
    Route::get('/anti-modalidades/{claseId}', [AnticipoConceptoController::class, 'getModalidadesPorClase']);
});

// ============================================================================
// CONCEPTOS + REGLAS (CRUD completo)
// Prefijo relativo: /api/anticipos/conceptos
// ============================================================================
Route::prefix('conceptos')->group(function () {
    Route::get('/',                     [AnticipoConceptoController::class, 'index']);
    Route::post('/',                    [AnticipoConceptoController::class, 'store']);
    Route::get('/{id}',                 [AnticipoConceptoController::class, 'show']);
    Route::put('/{id}',                 [AnticipoConceptoController::class, 'update']);
    Route::delete('/{id}',              [AnticipoConceptoController::class, 'destroy']);
    Route::patch('/{id}/toggle-estado', [AnticipoConceptoController::class, 'toggleEstado']);
});
