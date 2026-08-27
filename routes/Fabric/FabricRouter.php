<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Fabric\HcReportViewerController;
use App\Http\Controllers\Fabric\FabricViewerController;
use App\Http\Controllers\Fabric\LecturaPdfController;

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
    // Traslado asistencial — bi_from_tras_asistencial
    // =========================================================================
    Route::prefix('traslado-asistencial')->group(function () {
        Route::get('/', [\App\Http\Controllers\Fabric\BiTrasladoAsistencialController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Fabric\BiTrasladoAsistencialController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Fabric\BiTrasladoAsistencialController::class, 'show'])
            ->whereNumber('id');
        Route::put('/{id}', [\App\Http\Controllers\Fabric\BiTrasladoAsistencialController::class, 'update'])
            ->whereNumber('id');
        Route::post('/{id}/confirmar', [\App\Http\Controllers\Fabric\BiTrasladoAsistencialController::class, 'confirmar'])
            ->whereNumber('id');
    });

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

        // ── Endpoints LIGEROS (cacheados en Redis 5 min, catálogo/metadata) ──
        // NO llevan límite de concurrencia: el frontend los llama en paralelo
        // (uno por esquema) y bloquearlos rompe la carga del listado de vistas.

        // Contexto del usuario: grupos, esquemas permitidos y departamento
        Route::get('/context', [FabricViewerController::class, 'context']);

        // Workbook state (guardar/cargar estado del visor Excel)
        Route::get('/workbooks', [\App\Http\Controllers\Fabric\BiWorkbookController::class, 'index']);
        Route::get('/workbook/{schema}/{view}', [\App\Http\Controllers\Fabric\BiWorkbookController::class, 'show'])
            ->where('schema', '[a-z]{2,4}')
            ->where('view', '[A-Za-z0-9_]+');
        Route::post('/workbook/save', [\App\Http\Controllers\Fabric\BiWorkbookController::class, 'save']);
        Route::delete('/workbook/{id}', [\App\Http\Controllers\Fabric\BiWorkbookController::class, 'destroy']);

        // Workbook Manager (Mis Excels - CRUD de workbooks multi-vista)
        Route::get('/my-workbooks', [\App\Http\Controllers\Fabric\BiWorkbookManagerController::class, 'index']);
        Route::get('/my-workbook/{id}', [\App\Http\Controllers\Fabric\BiWorkbookManagerController::class, 'show'])
            ->where('id', '[0-9]+');
        Route::post('/my-workbook', [\App\Http\Controllers\Fabric\BiWorkbookManagerController::class, 'store']);
        Route::put('/my-workbook/{id}', [\App\Http\Controllers\Fabric\BiWorkbookManagerController::class, 'update'])
            ->where('id', '[0-9]+');
        Route::put('/my-workbook/{id}/state', [\App\Http\Controllers\Fabric\BiWorkbookManagerController::class, 'saveState'])
            ->where('id', '[0-9]+');
        Route::delete('/my-workbook/{id}', [\App\Http\Controllers\Fabric\BiWorkbookManagerController::class, 'destroy'])
            ->where('id', '[0-9]+');

        // Vistas de Fabric que el usuario puede ver
        Route::post('/views', [FabricViewerController::class, 'views']);

        // Columnas de una vista específica
        Route::post('/columns', [FabricViewerController::class, 'columns']);

        // ── Endpoints PESADOS (consultas reales a Fabric, pueden tardar minutos) ──
        // Estos sí llevan semáforo: protegen los workers de PHP-FPM y de Python.
        Route::middleware([\App\Http\Middleware\FabricConcurrencyLimiter::class])->group(function () {
            // Datos paginados de una vista
            Route::post('/data', [FabricViewerController::class, 'data']);

            // Agregación (GROUP BY) para tablas dinámicas
            Route::post('/aggregate', [FabricViewerController::class, 'aggregate']);

            // Export a Excel — síncrono (descarga directa, para datasets pequeños)
            Route::post('/export', [FabricViewerController::class, 'export']);

            // NUEVOS ENDPOINTS: Paginación optimizada para vistas grandes
            Route::post('/data/paginated', [FabricViewerController::class, 'dataPaginated']);
            Route::post('/estimate-rows', [FabricViewerController::class, 'estimateRows']);
        });

        // Export a Excel — asíncrono (segundo plano, recomendado para producción)
        Route::match(['get', 'post'], '/export/start', [FabricViewerController::class, 'exportStart']);
        // Ruta alternativa con schema/view en la URL (para frontend que usa GET)
        Route::get('/export/start/{schema}/{view}', [FabricViewerController::class, 'exportStartByUrl'])
            ->where('schema', '[a-z]{2,4}')
            ->where('view', '[A-Za-z0-9_]+');
        Route::get('/export/status/{jobId}', [FabricViewerController::class, 'exportStatus']);
        Route::get('/export/download/{jobId}', [FabricViewerController::class, 'exportDownload']);

        // R2 Parquet status polling (frontend llama cada 5s cuando status=generating)
        Route::get('/r2/status', [FabricViewerController::class, 'r2WarmStatus']);

        // Arranque JadeOne Desktop: ticket opaco (el JWT no viaja en el protocolo)
        Route::post('/desktop/launch', [\App\Http\Controllers\Fabric\FabricDesktopController::class, 'launch']);

        // SSE Stream — sin JWT, el jobId es token implícito (no pasa por auth middleware)
        // Se registra en routes/api.php fuera del grupo auth.
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
    // Dominios Permitidos OData (qué dominios pueden actualizar desde Excel)
    // =========================================================================
    Route::prefix('odata/allowed-domains')->group(function () {
        Route::get('/', [\App\Http\Controllers\AllowedDomainController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\AllowedDomainController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\AllowedDomainController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\AllowedDomainController::class, 'destroy']);
        Route::patch('/{id}/toggle', [\App\Http\Controllers\AllowedDomainController::class, 'toggleStatus']);
    });

    // =========================================================================
    // Lecturas Imagenología — Servir PDFs desde Azure File Share
    // =========================================================================
    Route::prefix('lecturas')->group(function () {
        Route::get('/pdf', [LecturaPdfController::class, 'show']);
        Route::get('/pdf/check', [LecturaPdfController::class, 'check']);
    });

    // =========================================================================
    // Métricas Graph-Fabric — Dashboard de monitoreo en tiempo real
    // Solo admins (se valida en frontend vía módulo)
    // =========================================================================
    Route::prefix('metrics')->group(function () {
        Route::get('/service', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'service']);
        Route::get('/top-views', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'topViews']);
        Route::get('/top-users', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'topUsers']);
        Route::get('/slow', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'slowQueries']);
        Route::get('/history', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'history']);
        Route::get('/fabric/active', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'fabricActive']);
        Route::get('/fabric/summary', [\App\Http\Controllers\Fabric\FabricMetricsController::class, 'fabricSummary']);

        // Logs de errores BI (timeout, fabric_error) — monitoreo y auto-mantenimiento
        Route::get('/error-logs', [\App\Http\Controllers\Fabric\BiVistaErrorLogController::class, 'index']);
        Route::get('/error-logs/by-view', [\App\Http\Controllers\Fabric\BiVistaErrorLogController::class, 'byView']);
        Route::post('/error-logs/{id}/resolve', [\App\Http\Controllers\Fabric\BiVistaErrorLogController::class, 'resolve']);
        Route::post('/error-logs/resolve-view', [\App\Http\Controllers\Fabric\BiVistaErrorLogController::class, 'resolveView']);
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
