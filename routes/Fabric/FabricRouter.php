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
    // HC Report Viewer — [DT].[VW_HC_ReportViewer]
    // =========================================================================
    Route::get('/hc-report-viewer/columnas', [HcReportViewerController::class, 'columnas']);
    Route::get('/hc-report-viewer', [HcReportViewerController::class, 'index']);

    // =========================================================================
    // Fabric Viewer — Gateway hacia API Python Graph-Fabric
    //
    // Flujo:
    //   Frontend (JWT) → Laravel valida auth + grupos GG-BD-* del usuario
    //   → Laravel reenvía a API Python con TOKEN_ADMIN
    //   → Devuelve solo las vistas/datos del esquema permitido al usuario
    // =========================================================================
    Route::prefix('viewer')->group(function () {

        // Contexto del usuario: grupos, esquemas permitidos y departamento
        // Llamar al inicializar el visor en el frontend
        Route::get('/context', [FabricViewerController::class, 'context']);

        // Vistas de Fabric que el usuario puede ver
        // Body vacío — usa los grupos del JWT autenticado
        Route::post('/views', [FabricViewerController::class, 'views']);

        // Columnas de una vista específica
        // Body: { schema_name, view_name }
        Route::post('/columns', [FabricViewerController::class, 'columns']);

        // Datos paginados de una vista
        // Body: { schema_name, view, columns[], filters{}, limit, offset, sort_col, sort_dir }
        Route::post('/data', [FabricViewerController::class, 'data']);

        // Export a Excel (descarga directa)
        // Body: { schema_name, view, columns[], filters{}, sort_col, sort_dir, max_rows }
        Route::post('/export', [FabricViewerController::class, 'export']);
    });
});
