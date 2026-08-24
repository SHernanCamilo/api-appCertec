<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiVistaAccessLog;
use App\Services\Fabric\BiVistaAuditService;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Gateway hacia la API Python Graph-Fabric.
 *
 * Flujo de seguridad:
 *   1. El JWT del frontend se verifica aquí en Laravel
 *   2. Se leen los grupos GG-BD-* del usuario desde users_grups (sincronizados en login)
 *   3. Se valida que el esquema solicitado esté en los grupos del usuario
 *   4. Se reenvía la solicitud a la API Python con TOKEN_ADMIN (token de servicio)
 *   5. Se devuelve la respuesta al frontend
 *
 * Endpoints:
 *   POST /api/fabric/viewer/views    → Vistas permitidas para el usuario
 *   POST /api/fabric/viewer/columns  → Columnas de una vista
 *   POST /api/fabric/viewer/data     → Datos paginados de una vista
 *   POST /api/fabric/viewer/export   → Export a Excel (descarga)
 */
class FabricViewerController extends Controller
{
    public function __construct(
        private GraphFabricGatewayService $gateway,
        private BiVistaAuditService $auditService
    ) {}

    // =========================================================================
    // VISTAS PERMITIDAS
    // =========================================================================

    /**
     * Retorna las vistas de Fabric que el usuario autenticado puede ver.
     * Usa los grupos GG-BD-* y el departamento almacenados en users_grups.
     *
     * POST /api/fabric/viewer/views
     *
     * Body: (vacío — toda la info viene del JWT del usuario autenticado)
     *
     * Response:
     * {
     *   "success": true,
     *   "grupos":   ["GG-BD-IN", "GG-BD-CO"],
     *   "esquemas": ["in", "co"],
     *   "departamento": "MA-TIC",
     *   "data": { ...respuesta de la API Py /api/catalog/views... }
     * }
     */
    public function views(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'nullable|string|max:20|alpha_dash',
            'refresh'     => 'nullable|boolean',
            'tipo'        => 'nullable|integer|in:1,2,3',
        ]);

        $user         = auth()->user();
        $schema       = $request->input('schema_name');
        $forceRefresh = $request->boolean('refresh');
        $tipo         = $request->filled('tipo') ? (int) $request->input('tipo') : null;

        $result = $this->gateway->getViewsForUser($user, $schema, $forceRefresh, $tipo);

        if (!$result['success']) {
            $code = $result['code'] ?? 200;
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error obteniendo vistas.',
                'data'    => [],
            ], $code === 403 ? 403 : 200);
        }

        return response()->json($result);
    }

    // =========================================================================
    // COLUMNAS DE UNA VISTA
    // =========================================================================

    /**
     * Retorna las columnas de una vista específica.
     * Valida que el usuario tenga acceso al esquema solicitado.
     *
     * POST /api/fabric/viewer/columns
     *
     * Body:
     * {
     *   "schema_name": "in",
     *   "view_name":   "VW_Inventory_Almacenes"
     * }
     */
    public function columns(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view_name'   => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);
        $view   = $request->view_name;

        $result = $this->gateway->getViewColumns($user, $schema, $view);

        if (!$result['success']) {
            $code = $result['code'] ?? 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $code);
        }

        return response()->json($result);
    }

    // =========================================================================
    // DATOS PAGINADOS
    // =========================================================================

    /**
     * Consulta datos paginados de una vista de Fabric.
     * Valida acceso al esquema, luego hace proxy a /api/data/dynamic.
     *
     * POST /api/fabric/viewer/data
     *
     * Body:
     * {
     *   "schema_name": "in",
     *   "view":        "VW_Inventory_Almacenes",
     *   "columns":     ["codigo", "producto", "cantidad"],  // [] = todas
     *   "filters":     {"estado": "ACTIVO", "producto": "%AMOX%"},
     *   "limit":       50,
     *   "offset":      0,
     *   "sort_col":    "codigo",
     *   "sort_dir":    "asc"
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "data": [...],
     *   "meta": {
     *     "total": 28766,
     *     "limit": 50,
     *     "offset": 0,
     *     "has_next": true,
     *     "elapsed_ms": 657.2
     *   }
     * }
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'columns'     => 'nullable|array',
            'columns.*'   => 'string|max:100',
            'filters'     => 'nullable|array',
            'limit'       => 'nullable|integer|min:1|max:5000',
            'offset'      => 'nullable|integer|min:0',
            'sort_col'    => 'nullable|string|max:100',
            'sort_dir'    => 'nullable|in:asc,desc',
            'skip_count'  => 'nullable|boolean',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);
        $view   = $request->view;

        $result = $this->gateway->queryViewData(
            $user,
            $schema,
            $view,
            [
                'columns'    => $request->input('columns', []),
                'filters'    => $request->input('filters', []),
                'limit'      => $request->input('limit', 50),
                'offset'     => $request->input('offset', 0),
                'sort_col'   => $request->input('sort_col', ''),
                'sort_dir'   => $request->input('sort_dir', 'asc'),
                'skip_count' => $request->boolean('skip_count', false),
            ]
        );

        if (!$result['success']) {
            $code = $result['code'] ?? 400;

            // Propagación del 422 "filters_required" de la API Python
            if (!empty($result['requires_filters'])) {
            return response()->json([
                'success'          => false,
                'requires_filters' => true,
                'message'          => $result['message'],
                'suggestions'      => $result['suggestions'] ?? [],
                'columns'          => $result['columns'] ?? [],
                'heavy_view'       => true,
                'schema'           => $result['schema'] ?? $schema,
                'view_name'        => $result['view_name'] ?? $view,
            ], 422);
            }

            $errorPayload = [
                'success' => false,
                'message' => $result['message'],
            ];
            if (!empty($result['estado'])) {
                $errorPayload['estado'] = $result['estado'];
            }

            return response()->json($errorPayload, $code);
        }

        $offset = (int) $request->input('offset', 0);
        if ($offset === 0) {
            $this->auditService->log(
                $user,
                $schema,
                $view,
                BiVistaAccessLog::ACCION_CONSULTA,
                $request,
                [
                    'filters'       => $request->input('filters', []),
                    'rows_returned' => (int) ($result['meta']['total'] ?? count($result['data'] ?? [])),
                    'elapsed_ms'    => (int) ($result['meta']['elapsed_ms'] ?? 0),
                    'success'       => true,
                ]
            );
        }

        return response()->json($result);
    }

    // =========================================================================
    // EXPORT A EXCEL
    // =========================================================================

    /**
     * Export SÍNCRONO — descarga directa (para archivos pequeños < 10k filas).
     * Útil en desarrollo o para exports rápidos.
     *
     * POST /api/fabric/viewer/export
     */
    public function export(Request $request): mixed
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'columns'     => 'nullable|array',
            'columns.*'   => 'string|max:100',
            'filters'     => 'nullable|array',
            'sort_col'    => 'nullable|string|max:100',
            'sort_dir'    => 'nullable|in:asc,desc',
            'max_rows'    => 'nullable|integer|min:1|max:1048576',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);

        $result = $this->gateway->exportViewExcel(
            $user,
            $schema,
            $request->view,
            [
                'columns'  => $request->input('columns', []),
                'filters'  => $request->input('filters', []),
                'sort_col' => $request->input('sort_col', ''),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'max_rows' => $request->input('max_rows', 100000),
            ]
        );

        if (!$result['success']) {
            $code = $result['code'] ?? 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $code);
        }

        $this->auditService->log(
            $user,
            $schema,
            $request->view,
            BiVistaAccessLog::ACCION_EXPORT_SYNC,
            $request,
            [
                'filters'       => $request->input('filters', []),
                'rows_returned' => (int) ($result['rows'] ?? 0),
                'metadata'      => ['filename' => $result['filename'] ?? null],
            ]
        );

        return response($result['content'], 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    // =========================================================================
    // EXPORT A EXCEL — ASÍNCRONO (recomendado para producción/Apache)
    // =========================================================================

    /**
     * Inicia un export en segundo plano (queue).
     * Soporta formato 'gzip' (NDJSON comprimido, 10x más rápido) o 'excel' (.xlsx).
     *
     * POST /api/fabric/viewer/export/start
     */
    public function exportStart(Request $request): JsonResponse
    {
        // Aceptar parámetros tanto de body (POST) como de query (GET)
        $schemaName = $request->input('schema_name', $request->query('schema_name'));
        $viewName   = $request->input('view', $request->query('view'));

        if (!$schemaName || !$viewName) {
            \Illuminate\Support\Facades\Log::warning('ExportStart: parámetros faltantes', [
                'method'       => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'all_input'    => $request->all(),
                'raw_body'     => substr($request->getContent(), 0, 500),
                'query'        => $request->query(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Parámetros requeridos: schema_name y view.',
            ], 422);
        }

        $user   = auth()->user();
        $schema = strtolower($schemaName);

        // Validar acceso antes de encolar
        if (!$this->gateway->tieneAccesoEsquema($user, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
            ], 403);
        }

        if (!$this->gateway->tieneAccesoVistaPorSede($user, $viewName, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso a la vista '{$viewName}' por sede.",
            ], 403);
        }

        // ─── R2 Parquet Pre-Warming ─────────────────────────────────────────────
        // Intentar usar el parquet pre-generado por Graph-Fabric.
        // Si está listo (~2s), se usa export/r2.
        // Si está generándose, el frontend hace polling.
        // Si es demasiado grande, forzar filtros.
        // Si R2 no está disponible, fallback al export stream clásico.
        $forceRefresh = (bool) $request->input('force_refresh', false);
        $r2Status = $this->tryR2Warm($schema, $viewName, $forceRefresh);

        if ($r2Status !== null) {
            $status = $r2Status['status'] ?? 'unknown';

            // READY o READY_STALE → exportar inmediatamente desde parquet
            if (in_array($status, ['ready', 'ready_stale'])) {
                return $this->exportFromR2(
                    $user, $schema, $viewName, $request, $r2Status
                );
            }

            // GENERATING → decirle al frontend que haga polling
            if ($status === 'generating') {
                return response()->json([
                    'success'     => true,
                    'r2_status'   => 'generating',
                    'message'     => $r2Status['message'] ?? 'El parquet se está generando...',
                    'estimated_s' => $r2Status['estimated_s'] ?? 60,
                    'row_count'   => $r2Status['row_count'] ?? null,
                ], 202);
            }

            // TOO_BIG → requiere filtros
            if ($status === 'too_big') {
                return response()->json([
                    'success'    => true,
                    'r2_status'  => 'too_big',
                    'message'    => 'La vista tiene más de 1M de filas. Aplique un filtro de fechas.',
                    'row_count'  => $r2Status['row_count'] ?? null,
                ], 200);
            }
        }

        // ─── Fallback: Export stream clásico (si R2 no disponible) ───────────────
        $jobId = \App\Jobs\FabricStreamExportJob::dispatch_and_track(
            $user->id,
            $schema,
            $viewName,
            [
                'columns'  => $request->input('columns', []),
                'filters'  => $request->input('filters', []),
                'sort_col' => $request->input('sort_col', ''),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'max_rows' => (int) $request->input('max_rows', 500000),
                'format'   => $request->input('format', 'xlsx'),
            ]
        );

        $this->auditService->log(
            $user,
            $schema,
            $viewName,
            BiVistaAccessLog::ACCION_EXPORT_INICIO,
            $request,
            [
                'filters'  => $request->input('filters', []),
                'metadata' => ['job_id' => $jobId],
            ]
        );

        return response()->json([
            'success'    => true,
            'job_id'     => $jobId,
            'message'    => 'Export iniciado en segundo plano.',
            'status_url' => "/api/fabric/viewer/export/status/{$jobId}",
        ], 202);
    }

    // ─── R2 Parquet Helpers ─────────────────────────────────────────────────────

    /**
     * Llama a Graph-Fabric /api/r2/warm para verificar si el parquet está listo.
     * Retorna null si R2 no está disponible (fallback a stream).
     */
    private function tryR2Warm(string $schema, string $viewName, bool $forceRefresh = false): ?array
    {
        try {
            $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
            $token   = config('fabric.api_key', '');

            $body = [
                'token'       => $token,
                'schema_name' => $schema,
                'view'        => $viewName,
            ];
            if ($forceRefresh) {
                $body['force'] = true; // Graph-Fabric regenera el parquet desde cero
            }

            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->post("{$baseUrl}/api/r2/warm", $body);

            if ($response->successful()) {
                return $response->json();
            }

            \Illuminate\Support\Facades\Log::info('[R2Warm] Response no exitosa', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::info('[R2Warm] No disponible, fallback a stream', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Exporta datos directamente desde el parquet R2 (camino rápido, ~2s).
     */
    private function exportFromR2(
        $user,
        string $schema,
        string $viewName,
        Request $request,
        array $r2Status,
    ): JsonResponse {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.api_key', '');

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(60)
                ->post("{$baseUrl}/api/data/export/r2", [
                    'token'       => $token,
                    'schema_name' => $schema,
                    'view'        => $viewName,
                    'filters'     => $request->input('filters', []),
                    'columns'     => $request->input('columns', []),
                    'max_rows'    => (int) $request->input('max_rows', 500000),
                    'format'      => 'gzip',
                ]);

            if (!$response->successful()) {
                // Fallback a stream si R2 export falla
                \Illuminate\Support\Facades\Log::warning('[R2Export] Fallo, fallback', [
                    'status' => $response->status(),
                ]);
                return $this->fallbackStreamExport($user, $schema, $viewName, $request);
            }

            // Guardar el NDJSON.gz en storage temporal
            $filename = "{$schema}_{$viewName}_" . now()->format('Ymd_His') . '.ndjson.gz';
            $path     = "exports/{$filename}";
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $response->body());

            // Registrar en cache como job "completado" para que exportDownload lo encuentre
            $jobId = 'r2_' . \Illuminate\Support\Str::random(12);
            $rows  = $r2Status['row_count'] ?? 0;

            \Illuminate\Support\Facades\Cache::put("fabric_export:{$jobId}", [
                'status'    => 'completed',
                'schema'    => $schema,
                'view_name' => $viewName,
                'rows'      => $rows,
                'path'      => $path,
                'filename'  => $filename,
                'format'    => 'ndjson.gz',
                'file_path' => $path,
                'size'      => strlen($response->body()),
            ], now()->addMinutes(15));

            $this->auditService->log(
                $user,
                $schema,
                $viewName,
                BiVistaAccessLog::ACCION_EXPORT_INICIO,
                $request,
                [
                    'filters'  => $request->input('filters', []),
                    'metadata' => ['job_id' => $jobId, 'source' => 'r2_parquet', 'rows' => $rows],
                ]
            );

            return response()->json([
                'success'    => true,
                'job_id'     => $jobId,
                'r2_status'  => 'ready',
                'message'    => "Datos listos desde parquet ({$rows} filas).",
                'rows'       => $rows,
                'status_url' => "/api/fabric/viewer/export/status/{$jobId}",
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[R2Export] Exception', ['error' => $e->getMessage()]);
            return $this->fallbackStreamExport($user, $schema, $viewName, $request);
        }
    }

    /**
     * Fallback: lanza el export stream clásico cuando R2 falla.
     */
    private function fallbackStreamExport($user, string $schema, string $viewName, Request $request): JsonResponse
    {
        $jobId = \App\Jobs\FabricStreamExportJob::dispatch_and_track(
            $user->id,
            $schema,
            $viewName,
            [
                'columns'  => $request->input('columns', []),
                'filters'  => $request->input('filters', []),
                'sort_col' => $request->input('sort_col', ''),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'max_rows' => (int) $request->input('max_rows', 500000),
                'format'   => $request->input('format', 'xlsx'),
            ]
        );

        $this->auditService->log(
            $user,
            $schema,
            $viewName,
            BiVistaAccessLog::ACCION_EXPORT_INICIO,
            $request,
            [
                'filters'  => $request->input('filters', []),
                'metadata' => ['job_id' => $jobId, 'source' => 'stream_fallback'],
            ]
        );

        return response()->json([
            'success'    => true,
            'job_id'     => $jobId,
            'message'    => 'Export iniciado en segundo plano (stream).',
            'status_url' => "/api/fabric/viewer/export/status/{$jobId}",
        ], 202);
    }

    /**
     * Polling del estado de R2 warm para el frontend.
     *
     * GET /api/fabric/viewer/r2/status?schema=dc&view=VW_Censo_Eal
     *
     * El frontend llama cada 5 segundos cuando exportStart devuelve r2_status=generating.
     * Cuando devuelve ready, el frontend llama exportStart de nuevo y ya sale por el fast path.
     */
    public function r2WarmStatus(Request $request): JsonResponse
    {
        $schema = $request->query('schema', '');
        $view   = $request->query('view', '');

        if (!$schema || !$view) {
            return response()->json(['success' => false, 'message' => 'schema y view requeridos'], 422);
        }

        $user = auth()->user();
        if (!$this->gateway->tieneAccesoEsquema($user, $schema)) {
            return response()->json(['success' => false, 'message' => 'Sin acceso'], 403);
        }

        $result = $this->tryR2Warm($schema, $view);

        if ($result === null) {
            return response()->json([
                'success'   => true,
                'r2_status' => 'unavailable',
                'message'   => 'R2 no disponible, use export stream.',
            ]);
        }

        return response()->json([
            'success'     => true,
            'r2_status'   => $result['status'] ?? 'unknown',
            'message'     => $result['message'] ?? '',
            'estimated_s' => $result['estimated_s'] ?? null,
            'row_count'   => $result['row_count'] ?? null,
            'age_hours'   => $result['age_hours'] ?? null,
            'size_mb'     => $result['size_mb'] ?? null,
        ]);
    }

    /**
     * Export con schema/view en la URL (para frontend que usa GET).
     * GET /api/fabric/viewer/export/start/{schema}/{view}
     */
    public function exportStartByUrl(Request $request, string $schema, string $view): JsonResponse
    {
        $request->merge(['schema_name' => $schema, 'view' => $view]);
        return $this->exportStart($request);
    }

    /**
     * Consulta el estado de un export en progreso.
     *
     * GET /api/fabric/viewer/export/status/{jobId}
     *
     * Response:
     * { "status": "pending|processing|completed|failed", "filename": "...", "size": 123456 }
     */
    public function exportStatus(string $jobId): JsonResponse
    {
        $status = \Illuminate\Support\Facades\Cache::get("fabric_export:{$jobId}");

        if ($status === null) {
            return response()->json([
                'success' => false,
                'message' => 'Export no encontrado o expirado.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $status,
        ]);
    }

    /**
     * Descarga el archivo Excel de un export completado.
     *
     * GET /api/fabric/viewer/export/download/{jobId}
     *
     * Response: archivo .xlsx
     */
    public function exportDownload(string $jobId): mixed
    {
        // Soportar JWT en query param: window.open no puede enviar headers.
        // Sin esto, el fallback del frontend cae en 401 → redirect → 405.
        $req = request();
        if (!$req->bearerToken() && $req->query('token')) {
            $req->headers->set('Authorization', 'Bearer ' . $req->query('token'));
        }

        $status = \Illuminate\Support\Facades\Cache::get("fabric_export:{$jobId}");

        if ($status === null || ($status['status'] ?? '') !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Export no completado o no encontrado.',
            ], 404);
        }

        $user = auth()->user();
        if ($user) {
            $this->auditService->log(
                $user,
                (string) ($status['schema'] ?? ''),
                (string) ($status['view'] ?? $status['view_name'] ?? ''),
                BiVistaAccessLog::ACCION_EXPORT_DESCARGA,
                request(),
                [
                    'rows_returned' => (int) ($status['rows'] ?? 0),
                    'metadata'      => [
                        'job_id'   => $jobId,
                        'filename' => $status['filename'] ?? null,
                    ],
                ]
            );
        }

        $path     = $status['path'] ?? $status['file_path'] ?? null;
        $filename = $status['filename'] ?? 'export.csv';
        $format   = $status['format'] ?? 'csv';

        if (!$path || !\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo no encontrado. Puede haber expirado.',
            ], 410);
        }

        // Ruta absoluta del archivo en disco
        $absolutePath = storage_path('app/' . $path);

        $contentType = match (true) {
            $format === 'csv'             => 'text/csv; charset=utf-8',
            str_contains($format, 'csv')  => 'text/csv; charset=utf-8',
            $format === 'gzip'            => 'application/gzip',
            $format === 'xlsx'            => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default                       => 'application/octet-stream',
        };

        // Limpiar el archivo despues de 5 minutos (permite reintentos del frontend)
        $jobIdForCleanup = $jobId;
        \Illuminate\Support\Facades\Cache::put("fabric_export_cleanup:{$jobIdForCleanup}", true, now()->addMinutes(5));
        
        // Programar limpieza con un job dispatch delayed o simplemente dejar que expire
        // El cleanup se hara via un scheduled command o la proxima request
        dispatch(function () use ($jobIdForCleanup) {
            sleep(300); // 5 minutos
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory("fabric_exports/{$jobIdForCleanup}");
            \Illuminate\Support\Facades\Cache::forget("fabric_export:{$jobIdForCleanup}");
            \Illuminate\Support\Facades\Cache::forget("fabric_export_cleanup:{$jobIdForCleanup}");
        })->afterResponse();

        return response()->download($absolutePath, $filename, [
            'Content-Type'   => $contentType,
            'X-Export-Format' => $format,
            'X-Export-Rows'  => (string)($status['rows'] ?? 0),
            'Cache-Control'  => 'no-store, no-cache',
        ]);
    }

    // =========================================================================
    // EXPORT SSE — DESACTIVADO (saturaba PHP-FPM workers)
    // =========================================================================

    /**
     * SSE desactivado — cada conexión SSE bloqueaba un worker PHP-FPM hasta 10 min.
     * Con 10 usuarios exportando = 10 workers muertos = VPS sin capacidad.
     *
     * El frontend ahora usa polling con GET /export/status/{jobId} (instantáneo, ~5ms).
     * Este endpoint se mantiene para compatibilidad pero responde inmediato con el status actual.
     *
     * GET /api/fabric/viewer/export/stream/{jobId}
     */
    public function exportStream(string $jobId): JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9\-_]{10,80}$/', $jobId)) {
            abort(400, 'jobId inválido.');
        }

        $status = \Illuminate\Support\Facades\Cache::get("fabric_export:{$jobId}");

        if ($status === null) {
            return response()->json([
                'success' => false,
                'message' => 'Export no encontrado. Usar GET /export/status/{jobId} en su lugar.',
                'use_polling' => true,
            ], 404);
        }

        // Responder una sola vez con el estado actual (no mantener conexión abierta)
        return response()->json([
            'success' => true,
            'data'    => $status,
            'notice'  => 'SSE desactivado. Usar GET /export/status/{jobId} para polling.',
        ]);
    }

    // =========================================================================
    // INFO DEL USUARIO (debug / contexto)
    // =========================================================================

    /**
     * Ejecutar agregación (GROUP BY) en una vista para tablas dinámicas.
     * POST /api/fabric/viewer/aggregate
     *
     * Fabric SQL hace el GROUP BY con millones de filas y devuelve solo
     * el resultado resumido (~50-500 filas) que el frontend puede pivotear.
     */
    public function aggregate(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'rows'        => 'required|array|min:1',
            'rows.*'      => 'string|max:100',
            'columns'     => 'nullable|array',
            'columns.*'   => 'string|max:100',
            'values'      => 'required|array|min:1',
            'values.*.field'       => 'required|string|max:100',
            'values.*.aggregation' => 'required|string|in:sum,count,avg,min,max,count_distinct',
            'filters'     => 'nullable|array',
            'limit'       => 'nullable|integer|min:1|max:50000',
            'sort_col'    => 'nullable|string|max:100',
            'sort_dir'    => 'nullable|in:asc,desc',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);
        $view   = $request->view;

        $result = $this->gateway->aggregate($user, $schema, $view, [
            'rows'     => $request->input('rows'),
            'columns'  => $request->input('columns', []),
            'values'   => $request->input('values'),
            'filters'  => $request->input('filters', []),
            'limit'    => $request->input('limit', 10000),
            'sort_col' => $request->input('sort_col', ''),
            'sort_dir' => $request->input('sort_dir', 'asc'),
        ]);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error en agregación.',
            ], $result['code'] ?? 500);
        }

        return response()->json($result);
    }

    // =========================================================================
    // PAGINACIÓN OPTIMIZADA PARA VISTAS GRANDES (EXCEL-LIKE UI)
    // =========================================================================

    /**
     * Obtener datos paginados con cursor para vistas grandes (100K+ filas).
     * Optimizado para interfaz Excel-like con virtual scrolling.
     *
     * POST /api/fabric/viewer/data/paginated
     *
     * Body:
     * {
     *   "schema_name": "dc",
     *   "view": "VW_Censo_Eal",
     *   "cursor": 0,          // offset
     *   "limit": 100,         // rows per page
     *   "filters": [          // optional
     *     {"field": "Sede", "operator": "equals", "value": "001"},
     *     {"field": "Edad", "operator": "gt", "value": 18}
     *   ],
     *   "sorts": [            // optional
     *     {"field": "FechaNacimiento", "direction": "desc"}
     *   ]
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "data": [...],           // rows for this page
     *   "cursor": 100,           // next offset
     *   "has_more": true,        // whether there are more rows
     *   "total": 5432            // total row count (cached)
     * }
     */
    public function dataPaginated(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'cursor'      => 'nullable|integer|min:0',
            'limit'       => 'nullable|integer|min:1|max:1000',
            'filters'     => 'nullable|array',
            'filters.*.field'    => 'required_with:filters|string|max:100',
            'filters.*.operator' => 'required_with:filters|string|in:contains,equals,notEquals,gt,gte,lt,lte,between,in',
            'filters.*.value'    => 'required_with:filters',
            'sorts'       => 'nullable|array',
            'sorts.*.field'     => 'required_with:sorts|string|max:100',
            'sorts.*.direction' => 'required_with:sorts|string|in:asc,desc',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);
        $view   = $request->view;

        // Validar acceso
        if (!$this->gateway->tieneAccesoEsquema($user, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
            ], 403);
        }

        if (!$this->gateway->tieneAccesoVistaPorSede($user, $view, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
            ], 403);
        }

        $cursor = $request->input('cursor', 0);
        $limit  = $request->input('limit', 100);
        $filters = $request->input('filters', []);
        $sorts  = $request->input('sorts', []);

        // Llamar al gateway para obtener datos paginados
        $result = $this->gateway->getDataPaginated($user, $schema, $view, [
            'cursor'  => $cursor,
            'limit'   => $limit,
            'filters' => $filters,
            'sorts'   => $sorts,
        ]);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error obteniendo datos paginados.',
            ], $result['code'] ?? 500);
        }

        // Auditar acceso
        $this->auditService->log(
            $user,
            $schema,
            $view,
            BiVistaAccessLog::ACCION_CONSULTA,
            $request,
            [
                'pagination' => true,
                'cursor'     => $cursor,
                'limit'      => $limit,
                'filters'    => $filters,
                'rows_returned' => count($result['data']),
            ]
        );

        return response()->json([
            'success'  => true,
            'data'     => $result['data'],
            'cursor'   => $cursor + count($result['data']),
            'has_more' => $result['has_more'],
            'total'    => $result['total'],
        ]);
    }

    /**
     * Obtener estimación de filas de una vista (para decidir estrategia de carga).
     *
     * POST /api/fabric/viewer/estimate-rows
     *
     * Body:
     * {
     *   "schema_name": "dc",
     *   "view": "VW_Censo_Eal"
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "count": 125430,
     *   "strategy": "paginated"  // "in-memory" | "duckdb" | "paginated"
     * }
     */
    public function estimateRows(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);
        $view   = $request->view;

        // Validar acceso
        if (!$this->gateway->tieneAccesoEsquema($user, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
            ], 403);
        }

        if (!$this->gateway->tieneAccesoVistaPorSede($user, $view, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
            ], 403);
        }

        $result = $this->gateway->estimateRowCount($user, $schema, $view);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error estimando filas.',
            ], $result['code'] ?? 500);
        }

        $count = $result['count'];
        $strategy = match(true) {
            $count < 10_000   => 'in-memory',
            $count < 100_000  => 'duckdb',
            default           => 'paginated'
        };

        return response()->json([
            'success'  => true,
            'count'    => $count,
            'strategy' => $strategy,
        ]);
    }

    /**
     * Retorna el contexto del usuario autenticado:
     * grupos GG-BD-*, esquemas permitidos y departamento.
     * Útil para el frontend al inicializar el visor.
     *
     * GET /api/fabric/viewer/context
     */
    public function context(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'tipo' => 'nullable|integer|in:1,2,3',
        ]);

        $user = auth()->user();
        $tipo = $request->filled('tipo') ? (int) $request->query('tipo') : null;
        $esquemasCatalogo = $this->gateway->getEsquemasCatalogoUsuario($user, $tipo);
        $siteContext = $this->gateway->resolveSiteContext($user);

        return response()->json([
            'success'                 => true,
            'user'                    => $user->email,
            'grupos'                  => $this->gateway->getGruposBd($user, $tipo),
            'esquemas'                => $this->gateway->getEsquemasPermitidos($user, $tipo),
            'esquemas_catalogo'       => $esquemasCatalogo,
            'tiene_vistas_delegadas'  => collect($esquemasCatalogo)->contains(fn ($e) => !empty($e['es_delegado'])),
            'departamento'            => $siteContext['department'],
            'site_codes'              => $siteContext['site_codes'],
            'is_national'             => $siteContext['is_national'],
            'catalogo'                => array_values($this->gateway->getCatalogoGrupos()),
            'tipo'                    => $tipo,
        ]);
    }
}
