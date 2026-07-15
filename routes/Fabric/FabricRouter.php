<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fabric\HcReportViewerController;
use App\Http\Controllers\Fabric\FabricViewerController;

/**
 * Rutas del módulo Fabric / Microsoft Analytics
 *
 * Prefijo: /api/fabric
 * Middleware: auth:api, check.user.active
 */

Route::middleware(['auth:api'])->group(function () {

    // =========================================================================
    // Parámetros BI — bi_grupos / bi_vistas → routes/Fabric/BiGruposRouter.php
    // =========================================================================
    require __DIR__ . '/BiGruposRouter.php';

    // =========================================================================
    // HC Report Viewer — [DT].[VW_HC_ReportViewer]
    // =========================================================================
    Route::get('/hc-report-viewer/columnas', [HcReportViewerController::class, 'columnas']);
    Route::get('/hc-report-viewer', [HcReportViewerController::class, 'index']);

    // =========================================================================
    // Fabric Viewer — Gateway hacia API Python Graph-Fabric
    //
    // Protección:
    //   - JWT auth (middleware auth:api)
    //   - Rate limiting por usuario (FabricRateLimiter)
    //   - Circuit Breaker (en el servicio)
    //   - Cache de queries (30s TTL)
    //   - Validación de acceso por grupos GG-BD-*
    // =========================================================================
    Route::prefix('viewer')->middleware([\App\Http\Middleware\FabricRateLimiter::class])->group(function () {

        // Contexto del usuario: grupos, esquemas permitidos y departamento
        Route::get('/context', [FabricViewerController::class, 'context']);

        // Vistas de Fabric que el usuario puede ver
        Route::post('/views', [FabricViewerController::class, 'views']);

        // Columnas de una vista específica
        Route::post('/columns', [FabricViewerController::class, 'columns']);

        // Datos paginados de una vista
        Route::post('/data', [FabricViewerController::class, 'data']);

        // Export a Excel — síncrono (descarga directa, para datasets pequeños)
        Route::post('/export', [FabricViewerController::class, 'export']);

        // Export a Excel — asíncrono (segundo plano, recomendado para producción)
        Route::match(['get', 'post'], '/export/start', [FabricViewerController::class, 'exportStart']);
        // Ruta alternativa con schema/view en la URL (para frontend que usa GET)
        Route::get('/export/start/{schema}/{view}', [FabricViewerController::class, 'exportStartByUrl'])
            ->where('schema', '[a-z]{2,4}')
            ->where('view', '[A-Za-z0-9_]+');
        Route::get('/export/status/{jobId}', [FabricViewerController::class, 'exportStatus']);
        Route::get('/export/download/{jobId}', [FabricViewerController::class, 'exportDownload']);
    });

    // =========================================================================
    // OData Links — CRUD (requiere autenticación Laravel)
    // =========================================================================
    Route::prefix('odata/links')->group(function () {
        Route::get('/', [\App\Http\Controllers\Fabric\ODataController::class, 'listLinks']);
        Route::post('/', [\App\Http\Controllers\Fabric\ODataController::class, 'createLink']);
        Route::delete('/{id}', [\App\Http\Controllers\Fabric\ODataController::class, 'deactivateLink']);
    });

    // =========================================================================
    // Permisos OData (Quien puede actualizar desde Excel por vista)
    // =========================================================================
    Route::prefix('bi-vistas/{id}/permissions')->group(function () {
        Route::get('/', [\App\Http\Controllers\Fabric\BiVistaPermissionController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Fabric\BiVistaPermissionController::class, 'store']);
        Route::delete('/{userId}', [\App\Http\Controllers\Fabric\BiVistaPermissionController::class, 'destroy']);
    });

    // =========================================================================
    // OData API Keys — Generar/Listar/Revocar (requiere autenticación)
    // =========================================================================
    Route::prefix('odata/api-keys')->group(function () {
        Route::get('/', [\App\Http\Controllers\Fabric\ODataController::class, 'listApiKeys']);
        Route::post('/', [\App\Http\Controllers\Fabric\ODataController::class, 'generateApiKey']);
        Route::delete('/{id}', [\App\Http\Controllers\Fabric\ODataController::class, 'revokeApiKey']);
    });
});
