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

        // Usar response()->file() que hace STREAMING sin cargar en RAM
        // Limpiar el archivo después de enviarlo (register_shutdown_function)
        register_shutdown_function(function () use ($jobId) {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory("fabric_exports/{$jobId}");
            \Illuminate\Support\Facades\Cache::forget("fabric_export:{$jobId}");
        });

        return response()->download($absolutePath, $filename, [
            'Content-Type'   => $contentType,
            'X-Export-Format' => $format,
            'X-Export-Rows'  => (string)($status['rows'] ?? 0),
            'Cache-Control'  => 'no-store, no-cache',
        ]);
    }

    // =========================================================================
    // EXPORT SSE — Server-Sent Events (reemplaza polling)
    // =========================================================================

    /**
     * Stream SSE del progreso de un export.
     * No requiere JWT — el jobId actúa como token implícito.
     *
     * GET /api/fabric/viewer/export/stream/{jobId}
     *
     * Envía eventos:
     *   data: {"progress":45,"rows":12000,"status":"processing","message":"..."}\n\n
     *
     * Cierra cuando status === 'completed' o 'failed', o tras 10 min de timeout.
     */
    public function exportStream(string $jobId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Validar formato de jobId (UUID o similar)
        if (!preg_match('/^[a-zA-Z0-9\-_]{10,80}$/', $jobId)) {
            abort(400, 'jobId inválido.');
        }

        return response()->stream(function () use ($jobId) {
            // Desactivar buffers de salida (Apache/nginx pueden bufferear SSE)
            if (ob_get_level()) {
                ob_end_clean();
            }

            $cacheKey   = "fabric_export:{$jobId}";
            $maxSeconds = 600; // 10 min timeout
            $startTime  = time();
            $interval   = 1_500_000; // 1.5 segundos en microsegundos

            // Enviar comentario SSE inicial para abrir conexión
            echo ": stream opened\n\n";
            if (ob_get_level()) ob_flush();
            flush();

            while ((time() - $startTime) < $maxSeconds) {
                // Si el cliente cerró la conexión, salir
                if (connection_aborted()) {
                    break;
                }

                $status = \Illuminate\Support\Facades\Cache::get($cacheKey);

                if ($status === null) {
                    // Job no encontrado — puede que aún no se haya encolado
                    $elapsed = time() - $startTime;
                    if ($elapsed > 30) {
                        // Después de 30s sin data, asumir expirado
                        echo "data: " . json_encode([
                            'status'   => 'failed',
                            'progress' => 0,
                            'rows'     => 0,
                            'message'  => 'Export no encontrado o expirado.',
                        ]) . "\n\n";
                        flush();
                        break;
                    }
                    // Enviar heartbeat mientras espera
                    echo ": waiting\n\n";
                    flush();
                    usleep($interval);
                    continue;
                }

                // Construir payload SSE
                $payload = [
                    'status'   => $status['status'] ?? 'pending',
                    'progress' => (int) ($status['progress'] ?? 0),
                    'rows'     => (int) ($status['rows'] ?? 0),
                    'message'  => $status['message'] ?? '',
                ];

                // Agregar campos extra cuando completado
                if (($status['status'] ?? '') === 'completed') {
                    $payload['filename']        = $status['filename'] ?? null;
                    $payload['file_size_human']  = $status['file_size_human'] ?? null;
                }

                echo "data: " . json_encode($payload) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();

                // Si terminó (completado o fallido), cerrar stream
                if (in_array($status['status'] ?? '', ['completed', 'failed'], true)) {
                    break;
                }

                usleep($interval);
            }
        }, 200, [
            'Content-Type'                => 'text/event-stream',
            'Cache-Control'               => 'no-cache, no-store, must-revalidate',
            'Connection'                  => 'keep-alive',
            'X-Accel-Buffering'           => 'no', // Nginx: desactivar proxy_buffering
            'Access-Control-Allow-Origin' => '*',
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
