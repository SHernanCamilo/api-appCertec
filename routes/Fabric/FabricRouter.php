<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fabric\HcReportViewerController;

/**
 * Rutas del módulo Fabric / Microsoft Analytics
 *
 * Prefijo: /api/fabric
 * Middleware: auth:api
 */

Route::middleware(['auth:api'])->group(function () {

    // =========================================================================
    // HC Report Viewer — [DT].[VW_HC_ReportViewer]
    // =========================================================================

    // Columnas disponibles en la vista (útil para el frontend)
    Route::get('/hc-report-viewer/columnas', [HcReportViewerController::class, 'columnas']);

    // Consulta con filtros y paginación
    // Filtros: documento_paciente, nombre_paciente, nombre_especialista, fecha_desde, fecha_hasta
    Route::get('/hc-report-viewer', [HcReportViewerController::class, 'index']);
});
