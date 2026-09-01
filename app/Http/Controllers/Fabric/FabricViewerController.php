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

        // ─── EXPORT ASÍNCRONO (único camino) ────────────────────────────────────
        // /api/data/export/start resuelve el parquet internamente:
        //   1. Parquet fresco  → DuckDB lo lee en ~1s   ← la mayoría
        //   2. Parquet viejo   → lo sirve ya y refresca en background
        //   3. Sin parquet     → Fabric en vivo y encola el parquet para la próxima
        // Con progreso real (0-100 + stage) y deduplicación automática.
        // Laravel solo hace relay: no genera el archivo, no usa Horizon.
        $asyncStart = app(\App\Services\Fabric\GraphAsyncExportService::class)->start(
            $user,
            $schema,
            $viewName,
            [
                'filters'  => $request->input('filters', []),
                'columns'  => $request->input('columns', []),
                'sort_col' => $request->input('sort_col', ''),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'max_rows' => (int) $request->input('max_rows', 1_000_000),
            ]
        );

        if ($asyncStart['success'] === true) {
            $this->auditService->log(
                $user,
                $schema,
                $viewName,
                BiVistaAccessLog::ACCION_EXPORT_INICIO,
                $request,
                [
                    'filters'  => $request->input('filters', []),
                    'metadata' => ['job_id' => $asyncStart['job_id'], 'source' => 'graph_async'],
                ]
            );

            return response()->json([
                'success'    => true,
                'job_id'     => $asyncStart['job_id'],
                'async'      => true,
                // reused=true: se engancho a un export identico (deduplicacion).
                // ready=true: el archivo ya estaba listo, el primer poll lo entrega.
                'reused'     => $asyncStart['reused'] ?? false,
                'ready'      => $asyncStart['ready'] ?? false,
                'message'    => 'Export iniciado en Graph-Fabric.',
                'status_url' => "/api/fabric/viewer/export/status/{$asyncStart['job_id']}",
            ], 202);
        }

        \Illuminate\Support\Facades\Log::warning('[ExportStart] async no disponible, usando job local', [
            'view'    => "{$schema}.{$viewName}",
            'message' => $asyncStart['message'] ?? null,
        ]);

        // ─── Fallback: job local (si el async de Graph no está disponible) ──────
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
     * Solo consulta el estado del parquet. NO lanza otra generación.
     * El polling cada 5s debe usar esto; POST /r2/warm solo al inicio.
     */
    private function tryR2Status(string $schema, string $viewName): ?array
    {
        try {
            $baseUrl = rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/');
            $token   = (string) (config('fabric.token_admin') ?: config('fabric.api_key', ''));

            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->get("{$baseUrl}/api/r2/warm", [
                    'schema' => $schema,
                    'view'   => $viewName,
                    'token'  => $token,
                ]);

            if ($response->successful() || $response->status() === 202) {
                $json = $response->json();
                $status = $json['status'] ?? 'unknown';
                if ($status !== 'generating') {
                    \Illuminate\Support\Facades\Log::info('[R2Status]', [
                        'r2_status' => $status,
                        'schema'    => $schema,
                        'view'      => $viewName,
                    ]);
                }
                return $json;
            }

            \Illuminate\Support\Facades\Log::info('[R2Status] no exitoso', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::info('[R2Status] error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Gestiona la fase de conversión a .xlsx después de que Graph-Fabric terminó.
     *
     * Estados que devuelve al frontend:
     *   - processing "Generando Excel..."  → la conversión está en cola/corriendo
     *   - completed                        → el archivo está en disco, descarga instantánea
     *   - failed                           → la conversión falló
     *
     * @param  array<string,mixed>  $graphData
     * @return array<string,mixed>
     */
    private function trackXlsxConversion(string $jobId, array $graphData): array
    {
        $conversion = \Illuminate\Support\Facades\Cache::get(
            \App\Jobs\ConvertGraphExportToXlsxJob::cacheKey($jobId)
        );

        // Primera vez que vemos el export listo → encolar la conversión.
        // Se escribe el cache ANTES de despachar para que el siguiente poll
        // (2,5 s después) no vea null y despache un segundo job. Esto evitaba
        // una race condition que generaba el xlsx DOS veces.
        if ($conversion === null) {
            $ctx = \Illuminate\Support\Facades\Cache::get("graph_async_export:{$jobId}", []);

            \Illuminate\Support\Facades\Cache::put(
                \App\Jobs\ConvertGraphExportToXlsxJob::cacheKey($jobId),
                ['status' => 'converting', 'message' => 'Generando Excel...', 'started_at' => time()],
                1800
            );

            \App\Jobs\ConvertGraphExportToXlsxJob::dispatch(
                $jobId,
                (string) ($ctx['schema'] ?? 'export'),
                (string) ($ctx['view'] ?? $jobId)
            );

            return array_merge($graphData, [
                'status'   => 'processing',
                'progress' => 92,
                'message'  => 'Generando Excel...',
                'stage'    => 'Generando Excel',
            ]);
        }

        $convStatus = (string) ($conversion['status'] ?? 'converting');

        if ($convStatus === 'ready') {
            return array_merge($graphData, [
                'status'          => 'completed',
                'progress'        => 100,
                'rows'            => (int) ($conversion['rows'] ?? $graphData['rows'] ?? 0),
                'filename'        => $conversion['filename'] ?? null,
                'file_size'       => (int) ($conversion['bytes'] ?? 0),
                'file_size_human' => $conversion['file_size_human'] ?? null,
                'format'          => $conversion['format'] ?? 'xlsx',
                'message'         => $conversion['message'] ?? 'Descarga lista.',
                'stage'           => 'Listo',
            ]);
        }

        if ($convStatus === 'failed') {
            return array_merge($graphData, [
                'success' => false,
                'status'  => 'failed',
                'message' => $conversion['message'] ?? 'No se pudo generar el Excel.',
            ]);
        }

        // converting: reportar progreso que avanza para que el usuario sepa que
        // algo pasa. El 92 → 96 fijo que usábamos antes dejaba la barra clavada
        // 40+ segundos. Ahora se calcula un porcentaje suave basado en el running_s
        // del job de Graph (que ya terminó) + el tiempo que lleva el job de xlsx.
        $elapsed = time() - (int) ($conversion['started_at'] ?? time());
        $pct     = min(99, 92 + (int) (7 * min(1, $elapsed / 60)));

        return array_merge($graphData, [
            'status'   => 'processing',
            'progress' => $pct,
            'message'  => 'Generando Excel (' . number_format((int) ($graphData['rows'] ?? 0)) . ' filas)...',
            'stage'    => 'Generando Excel',
        ]);
    }

    /**
     * Descarga el archivo de un job async de Graph-Fabric y lo entrega al frontend.
     *
     * El body es gzip (NDJSON). El frontend lo descomprime y arma el .xlsx.
     * El archivo temporal se borra tras enviarlo.
     */
    private function downloadFromGraphAsync(string $jobId): mixed
    {
        // Dos consumidores distintos, un solo job:
        //
        //   as=data (grilla)  → sirve el NDJSON.gz crudo (~12 MB). El navegador
        //                       lo descomprime y lo pinta en AG Grid en segundos.
        //                       Es lo que carga la vista al terminar el polling.
        //
        //   as=file (defecto) → sirve el .xlsx de 200+ MB para guardarlo en disco.
        //                       El navegador NUNCA lo parsea; solo lo baja. Antes
        //                       se pasaba ese xlsx gigante a SheetJS y congelaba
        //                       la pagina (238 MB → varios GB de RAM en el tab).
        $as = (string) request()->query('as', 'file');

        return $as === 'data'
            ? $this->serveGraphDataForGrid($jobId)
            : $this->serveGraphXlsxFile($jobId);
    }

    /**
     * Sirve el NDJSON.gz crudo de Graph para pintar la grilla (descarga liviana).
     */
    private function serveGraphDataForGrid(string $jobId): mixed
    {
        $download = app(\App\Services\Fabric\GraphAsyncExportService::class)->download($jobId);

        if (($download['success'] ?? false) !== true) {
            return response()->json([
                'success' => false,
                'message' => $download['message'] ?? 'No se pudieron obtener los datos.',
            ], 502);
        }

        $gzPath = (string) ($download['path'] ?? '');

        if ($gzPath === '' || !is_file($gzPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Los datos expiraron. Vuelva a exportar.',
            ], 410);
        }

        // ── Se descomprime AQUI y se sirve NDJSON plano ──────────────────────
        //
        // Antes se entregaba el .gz crudo con 'Content-Encoding: identity' y el
        // navegador lo descomprimia con DecompressionStream. Eso se rompia en
        // produccion: algun filtro intermedio (mod_deflate / mod_brotli / el
        // proxy) recomprimia la respuesta y el `Header unset Content-Encoding`
        // del .htaccess borraba el aviso, asi que el navegador entregaba a
        // Angular bytes comprimidos sin decodificar. La grilla terminaba con
        // nombres de columna binarios tipo "Õÿù¯?þí/ðjõ".
        //
        // Servir texto plano quita la ambiguedad: la compresion la negocian
        // Apache y el navegador por Accept-Encoding / Content-Encoding, que es
        // el mecanismo estandar y el unico que los proxies respetan bien. El
        // ancho de banda no se pierde: public/.htaccess mete
        // application/x-ndjson en mod_deflate.
        //
        // Se transmite linea por linea con gzgets: RAM constante, da igual si
        // el export son 500 filas o 500.000.
        $rows = (int) ($download['rows'] ?? 0);

        // Guarda: validar que el .gz de verdad contiene NDJSON antes de empezar
        // a transmitir. Una vez abierto el stream ya no se puede devolver un
        // error HTTP, y el frontend recibiria basura sin explicacion.
        $firstLine = $this->peekFirstNdjsonLine($gzPath);

        if ($firstLine === null) {
            @unlink($gzPath);
            \Illuminate\Support\Facades\Log::error('[ExportData] el archivo no contiene NDJSON valido', [
                'job_id'      => $jobId,
                'bytes'       => (int) @filesize($gzPath),
                'primeros_16' => $this->hexPreview($gzPath),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Los datos llegaron corruptos del servidor de datos. Reintente la actualizacion.',
            ], 502);
        }

        return response()->stream(
            function () use ($gzPath) {
                $gz = gzopen($gzPath, 'rb');

                if ($gz === false) {
                    return;
                }

                try {
                    while (($line = gzgets($gz)) !== false) {
                        if (trim($line) === '') {
                            continue;
                        }

                        echo rtrim($line, "\r\n"), "\n";
                    }
                } finally {
                    gzclose($gz);
                    // NO se borra el .gz aqui: el boton "Excel" (ConvertGraph...)
                    // lo necesita para generar el xlsx. Lo limpia exports:cleanup
                    // por TTL. Borrarlo aqui causaba "Error al descargar el
                    // archivo" cuando el otro consumidor pedia el mismo job.
                }
            },
            200,
            [
                // application/x-ndjson es el tipo correcto y es texto: Apache lo
                // puede comprimir y el navegador lo decodifica solo.
                'Content-Type'      => 'application/x-ndjson; charset=utf-8',
                'X-Export-Format'   => 'ndjson',
                'X-Export-Rows'     => (string) $rows,
                'Cache-Control'     => 'no-store, no-cache',
                // Sin esto nginx/Apache pueden bufferar la respuesta completa.
                'X-Accel-Buffering' => 'no',
            ]
        );
    }

    /**
     * Lee la primera linea util de un .gz y la valida como JSON.
     *
     * Devuelve la linea, o null si el archivo no se puede descomprimir o su
     * primera linea no es un objeto JSON. Sirve para no empezar a transmitir
     * un stream corrupto, cuando ya es tarde para devolver un error HTTP.
     */
    private function peekFirstNdjsonLine(string $gzPath): ?string
    {
        $gz = gzopen($gzPath, 'rb');

        if ($gz === false) {
            return null;
        }

        try {
            // Se revisan unas pocas lineas: la primera podria venir vacia.
            for ($i = 0; $i < 5; $i++) {
                $line = gzgets($gz);

                if ($line === false) {
                    return null;
                }

                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                return is_array($decoded) && $decoded !== [] ? $line : null;
            }
        } finally {
            gzclose($gz);
        }

        return null;
    }

    /**
     * Primeros 16 bytes de un archivo en hex, para los logs de diagnostico.
     *
     * Con esto se identifica de un vistazo qué llegó realmente:
     *   1f8b08... → gzip     504b0304... → zip/xlsx
     *   7b22...   → JSON     otra cosa   → brotli u otro encoding sin magic
     */
    private function hexPreview(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '(ilegible)';
        }

        $bytes = (string) fread($handle, 16);
        fclose($handle);

        return implode(' ', array_map(
            static fn (string $b): string => str_pad(dechex(ord($b)), 2, '0', STR_PAD_LEFT),
            str_split($bytes) ?: []
        ));
    }

    /**
     * Sirve el .xlsx ya generado en cola. Descarga instantánea: solo entrega el
     * archivo, sin convertir nada dentro del request (eso causaba timeouts y el 405).
     */
    private function serveGraphXlsxFile(string $jobId): mixed
    {
        $conversion = \Illuminate\Support\Facades\Cache::get(
            \App\Jobs\ConvertGraphExportToXlsxJob::cacheKey($jobId)
        );

        if ($conversion === null || ($conversion['status'] ?? '') !== 'ready') {
            return response()->json([
                'success' => false,
                'message' => 'El Excel aun se esta generando. Espere a que el progreso llegue al 100%.',
            ], 409);
        }

        $path = (string) ($conversion['path'] ?? '');

        if ($path === '' || !is_file($path)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo expiro. Vuelva a exportar.',
            ], 410);
        }

        $ctx  = \Illuminate\Support\Facades\Cache::get("graph_async_export:{$jobId}", []);
        $user = auth()->user();

        if ($user) {
            $this->auditService->log(
                $user,
                (string) ($ctx['schema'] ?? ''),
                (string) ($ctx['view'] ?? ''),
                BiVistaAccessLog::ACCION_EXPORT_DESCARGA,
                request(),
                [
                    'rows_returned' => (int) ($conversion['rows'] ?? 0),
                    'metadata'      => [
                        'job_id' => $jobId,
                        'source' => 'graph_async',
                        'format' => $conversion['format'] ?? 'xlsx',
                    ],
                ]
            );
        }

        $format      = (string) ($conversion['format'] ?? 'xlsx');
        $contentType = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=utf-8';

        // IMPORTANTE: no forzar Content-Length ni Content-Encoding a mano.
        // response()->download() (BinaryFileResponse de Symfony) ya calcula el
        // Content-Length real y gestiona el envío binario. Sobrescribirlos hacía
        // que el navegador recibiera un archivo cortado y Excel dijera "el
        // formato o la extensión no son válidos".
        //
        // Para que el proxy no recomprima el xlsx (un .xlsx ya es un ZIP) está
        // la regla de mod_deflate en el .htaccess, que es el lugar correcto.
        //
        // No se borra tras enviar: el usuario puede pedir la grilla y luego el
        // archivo, o volver a descargar. Lo limpia la expiracion de cache.
        return response()->download(
            $path,
            (string) ($conversion['filename'] ?? "export.{$format}"),
            [
                'Content-Type'    => $contentType,
                'X-Export-Format' => $format,
                'X-Export-Rows'   => (string) ($conversion['rows'] ?? 0),
                'Cache-Control'   => 'no-store, no-cache',
            ]
        );
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

        $result = $this->tryR2Status($schema, $view);

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
        // ── Job async de Graph-Fabric: proxear el progreso real (0-100 + running_s)
        if (\Illuminate\Support\Facades\Cache::has("graph_async_export:{$jobId}")) {
            $data = app(\App\Services\Fabric\GraphAsyncExportService::class)->status($jobId);

            // Cuando Graph ya tiene los datos, falta convertirlos a .xlsx.
            // Esa conversión va en cola (no en la petición de descarga) porque
            // con 500K+ filas tarda 1-2 min y el request HTTP se caía por timeout.
            if (($data['status'] ?? '') === 'completed') {
                $data = $this->trackXlsxConversion($jobId, $data);
            }

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        }

        // ── Job local (fallback legacy)
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

        // ── Job async de Graph-Fabric: traer el gzip y entregarlo al frontend.
        //    El frontend descomprime el NDJSON y arma el .xlsx.
        if (\Illuminate\Support\Facades\Cache::has("graph_async_export:{$jobId}")) {
            return $this->downloadFromGraphAsync($jobId);
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

        // GUARDA: no entregar un archivo vacío (0 bytes). Evita el "Excel en blanco".
        if (@filesize($absolutePath) === 0) {
            \Illuminate\Support\Facades\Log::warning('[ExportDownload] Archivo vacio, rechazado', [
                'job_id' => $jobId, 'path' => $path,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'El archivo generado esta vacio. La vista no devolvio datos. Reintente con "Actualizar todo".',
            ], 422);
        }

        $contentType = match (true) {
            $format === 'csv'             => 'text/csv; charset=utf-8',
            str_contains($format, 'csv')  => 'text/csv; charset=utf-8',
            $format === 'gzip'            => 'application/gzip',
            $format === 'xlsx'            => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default                       => 'application/octet-stream',
        };

        // No programar sleep() aquí: con php artisan serve / QUEUE sync
        // bloquea toda la API. El cache de fabric_export ya expira (15 min)
        // y exports:cleanup borra archivos viejos.
        $this->cleanupStaleExportFiles();

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

    /**
     * Borra gz/xlsx temporales de más de 30 min. Rápido: no sleep, no cola.
     */
    private function cleanupStaleExportFiles(): void
    {
        try {
            $cutoff = time() - 1800;
            foreach (['exports', 'fabric_exports'] as $dir) {
                $base = storage_path('app/' . $dir);
                if (!is_dir($base)) {
                    continue;
                }
                foreach (scandir($base) ?: [] as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $full = $base . DIRECTORY_SEPARATOR . $name;
                    $mtime = @filemtime($full);
                    if ($mtime === false || $mtime > $cutoff) {
                        continue;
                    }
                    if (is_dir($full)) {
                        \Illuminate\Support\Facades\File::deleteDirectory($full);
                    } else {
                        @unlink($full);
                    }
                }
            }
        } catch (\Throwable) {
            // non-critical
        }
    }
}
