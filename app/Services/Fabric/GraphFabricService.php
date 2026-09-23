<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de la API Graph-Fabric (Python) para el Monitor de Parquets.
 *
 * PRINCIPIO DE ARQUITECTURA
 * -------------------------
 * Graph-Fabric es el dueño de TODA la lógica de generación de parquets: locks,
 * cupo global de concurrencia, deduplicación y carriles (lanes). Laravel es
 * únicamente el gateway: orquesta las llamadas y muestra el resultado.
 *
 * Por eso aquí NO hay colas, ni jobs, ni lógica de generación: solo llamadas
 * HTTP. Para forzar una regeneración se usa el flujo warm + polling, que ya
 * deduplica del lado de Graph-Fabric si dos usuarios fuerzan la misma vista.
 *
 * SEGURIDAD
 * ---------
 * El token de servicio (admin) vive solo en el backend. El navegador nunca lo
 * ve: llama endpoints internos de Laravel que hacen de proxy hacia esta API.
 *
 * NUNCA llamar /api/r2/generate (síncrono, tarda minutos) desde un request web.
 */
class GraphFabricService
{
    /** Timeout corto: warm (POST y GET) responden rápido. */
    private const TIMEOUT_RAPIDO = 15;

    /** Timeout para consultas de catálogo/estado. */
    private const TIMEOUT_ESTADO = 30;

    /** Timeout para datos en vivo contra Fabric (puede tardar). */
    private const TIMEOUT_EN_VIVO = 120;

    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.graph_fabric.url', 'http://127.0.0.1:8001'),
            '/'
        );
        $this->token = (string) config('services.graph_fabric.token', '');
    }

    // =========================================================================
    // A. FORZAR GENERACIÓN — flujo warm + polling (NO bloqueante)
    // =========================================================================

    /**
     * Paso 1: dispara la generación y responde en <200ms.
     *
     * `force = true` regenera aunque el parquet parezca fresco (botón rayo).
     * Aquí schema_name y view van SEPARADOS (schema sin punto).
     *
     * @return array{ok:bool, status?:string, poll_interval_s?:int, estimated_s?:int, message?:string, code?:int}
     */
    public function warmView(string $schema, string $view, bool $force = true): array
    {
        $resp = $this->request(
            'post',
            '/api/r2/warm',
            [
                'token'       => $this->token,
                'schema_name' => $schema,
                'view'        => $view,
                'force'       => $force,
            ],
            self::TIMEOUT_RAPIDO
        );

        if (!$resp['ok']) {
            return $resp;
        }

        $data = $resp['data'];

        return [
            'ok'              => true,
            'status'          => (string) ($data['status'] ?? 'generating'),
            'poll_interval_s' => (int) ($data['poll_interval_s'] ?? 5),
            'estimated_s'     => (int) ($data['estimated_s'] ?? 0),
            'message'         => $data['message'] ?? null,
        ];
    }

    /**
     * Paso 2: consulta el estado del warm. El front hace polling cada
     * `poll_interval_s` mientras status == "generating".
     *
     * Estados: ready | ready_stale | generating | too_big
     *
     * @return array{ok:bool, status?:string, source?:string, age_hours?:float, size_mb?:float, row_count?:int, generating?:bool, estimated_s?:int, message?:string, poll_interval_s?:int, code?:int}
     */
    public function warmStatus(string $schema, string $view): array
    {
        $resp = $this->request(
            'get',
            '/api/r2/warm',
            [
                'token'  => $this->token,
                'schema' => $schema,
                'view'   => $view,
            ],
            self::TIMEOUT_RAPIDO
        );

        if (!$resp['ok']) {
            return $resp;
        }

        $d = $resp['data'];

        return [
            'ok'              => true,
            'status'          => (string) ($d['status'] ?? 'generating'),
            'source'          => $d['source'] ?? null,
            'age_hours'       => isset($d['age_hours']) ? (float) $d['age_hours'] : null,
            'size_mb'         => isset($d['size_mb']) ? (float) $d['size_mb'] : null,
            'row_count'       => isset($d['row_count']) ? (int) $d['row_count'] : null,
            'generating'      => (bool) ($d['generating'] ?? false),
            'estimated_s'     => (int) ($d['estimated_s'] ?? 0),
            'message'         => $d['message'] ?? null,
            'poll_interval_s' => (int) ($d['poll_interval_s'] ?? 5),
        ];
    }

    // =========================================================================
    // B. MONITOREO — tarjetas, tabla y panel "Generando ahora"
    // =========================================================================

    /**
     * Schedule completo: stats para tarjetas + views para la tabla.
     *
     * @return array{ok:bool, data?:array, message?:string, code?:int}
     */
    public function schedule(): array
    {
        return $this->request('get', '/api/r2/schedule', ['token' => $this->token], self::TIMEOUT_ESTADO);
    }

    /**
     * Estado en vivo: summary (incluye `overdue` = riesgo de SLA) y
     * `generating_now` (lo que se está generando en este momento, con stage,
     * running_s e is_stuck).
     *
     * @return array{ok:bool, data?:array, message?:string, code?:int}
     */
    public function statusLive(): array
    {
        return $this->request('get', '/api/r2/status', ['token' => $this->token], self::TIMEOUT_ESTADO);
    }

    // =========================================================================
    // C. GESTIÓN DE VISTAS EN EL SCHEDULE
    // =========================================================================

    /**
     * Agrega o actualiza una vista en el schedule de Graph-Fabric.
     *
     * priority: critical/high/realtime | medium/normal/operativo |
     *           low/analitico | historico
     */
    public function scheduleUpsert(
        string $schema,
        string $view,
        int $refreshIntervalMin,
        string $priority = 'medium',
        string $groupName = 'General'
    ): array {
        return $this->request(
            'post',
            '/api/r2/schedule',
            [
                'token'                => $this->token,
                'schema_name'          => $schema,
                'view'                 => $view,
                'refresh_interval_min' => $refreshIntervalMin,
                'priority'             => $priority,
                'group_name'           => $groupName,
            ],
            self::TIMEOUT_ESTADO
        );
    }

    /**
     * Desactiva (saca del schedule) una vista. schema y view van SEPARADOS.
     *
     * Graph-Fabric espera estos parámetros en la QUERY STRING, no en el body:
     * enviarlos en el body (comportamiento por defecto de Http::delete) devuelve
     * HTTP 422. Por eso se arma la URL con la query directamente.
     */
    public function scheduleDelete(string $schema, string $view): array
    {
        $query = http_build_query([
            'token'  => $this->token,
            'schema' => $schema,
            'view'   => $view,
        ]);

        return $this->request(
            'delete',
            '/api/r2/schedule?' . $query,
            [], // sin body: los params van en la query string
            self::TIMEOUT_ESTADO
        );
    }

    /**
     * Ejecuta el cron manualmente. Graph-Fabric responde 202 y corre en
     * background: aquí solo se confirma que la corrida fue iniciada.
     */
    public function scheduleRun(): array
    {
        return $this->request(
            'post',
            '/api/r2/schedule/run',
            ['token' => $this->token],
            self::TIMEOUT_ESTADO
        );
    }

    // =========================================================================
    // D. TIEMPO REAL — Notificaciones de Interconsultas (NO usa parquet)
    // =========================================================================

    /**
     * Consulta la vista de notificaciones de interconsultas DIRECTO en Fabric,
     * sin parquet ni caché.
     *
     * Se usa en el job que notifica a los especialistas cuando una interconsulta
     * es SOLICITADA o ANULADA: el parquet puede tener minutos u horas de
     * antigüedad, así que la notificación saldría tarde o sobre un estado viejo.
     *
     * Formato de `$filtros` (igual que /api/data/dynamic):
     *   ['Estado' => 'Solicitada']                 → igualdad exacta
     *   ['Estado' => '%Anulada%']                  → LIKE
     *   ['Fecha'  => ['2026-09-01','2026-09-30']]  → rango BETWEEN
     *
     * `source == "fabric_live"` en la respuesta confirma que vino en vivo.
     *
     * NOTA: aun en vivo, Fabric tiene un lag interno de sincronización (~1 min
     * normalmente). Es el piso físico; no se puede bajar desde la aplicación.
     *
     * @param  array<string,mixed>  $filtros
     * @param  array{columns?:array<int,string>|string, sort_col?:string, sort_dir?:string, limit?:int}  $opciones
     * @return array{ok:bool, data?:array, message?:string, code?:int}
     */
    public function interconsultasEnVivo(array $filtros = [], array $opciones = []): array
    {
        $query = ['token' => $this->token];

        if ($filtros !== []) {
            // filters es un JSON URL-encoded. Http::get() ya urlencodea el valor.
            $query['filters'] = json_encode($filtros, JSON_UNESCAPED_UNICODE);
        }

        if (!empty($opciones['columns'])) {
            $cols = $opciones['columns'];
            $query['columns'] = is_array($cols) ? implode(',', $cols) : (string) $cols;
        }
        if (!empty($opciones['sort_col'])) {
            $query['sort_col'] = (string) $opciones['sort_col'];
        }
        if (!empty($opciones['sort_dir'])) {
            $dir = strtolower((string) $opciones['sort_dir']);
            $query['sort_dir'] = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';
        }
        if (!empty($opciones['limit'])) {
            // Tope duro de la API.
            $query['limit'] = min((int) $opciones['limit'], 50000);
        }

        return $this->request(
            'get',
            '/api/notificaciones/interconsultas',
            $query,
            self::TIMEOUT_EN_VIVO
        );
    }

    // =========================================================================
    // Infraestructura HTTP
    // =========================================================================

    /**
     * Ejecuta la llamada y normaliza el resultado y los errores.
     *
     * Traducción de errores al usuario:
     *   401/403 → token inválido o sin permiso admin
     *   503     → R2 no configurado o Graph-Fabric caído
     *
     * @param  'get'|'post'|'delete'  $metodo
     * @param  array<string,mixed>    $payload
     * @return array{ok:bool, data?:array, status?:int, message?:string, code?:int}
     */
    private function request(string $metodo, string $path, array $payload, int $timeout): array
    {
        if ($this->token === '') {
            return [
                'ok'      => false,
                'code'    => 503,
                'message' => 'Graph-Fabric no está configurado: falta el token de servicio.',
            ];
        }

        $url = $this->baseUrl . $path;

        try {
            $cliente = Http::timeout($timeout)->connectTimeout(10)->acceptJson();

            $response = match ($metodo) {
                'post'   => $cliente->post($url, $payload),
                'delete' => $cliente->delete($url, $payload),
                default  => $cliente->get($url, $payload),
            };

            if ($response->successful()) {
                return ['ok' => true, 'data' => $response->json() ?? [], 'status' => $response->status()];
            }

            $status = $response->status();

            Log::warning('[GraphFabric] respuesta no exitosa', [
                'path'   => $path,
                'status' => $status,
                'body'   => substr($response->body(), 0, 300),
            ]);

            return [
                'ok'      => false,
                'code'    => $status,
                'message' => $this->mensajeError($status),
            ];
        } catch (\Throwable $e) {
            Log::error('[GraphFabric] excepción en la llamada', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'code'    => 503,
                'message' => 'No se pudo conectar con Graph-Fabric. Verifique que el servicio esté activo.',
            ];
        }
    }

    /** Mensaje en español según el código HTTP devuelto por Graph-Fabric. */
    private function mensajeError(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'Token de servicio inválido o sin permisos de administrador en Graph-Fabric.',
            $status === 404                     => 'El recurso solicitado no existe en Graph-Fabric.',
            $status === 503                     => 'Graph-Fabric no está disponible o R2 no está configurado.',
            $status >= 500                      => "Graph-Fabric respondió con un error interno (HTTP {$status}).",
            default                             => "Graph-Fabric rechazó la solicitud (HTTP {$status}).",
        };
    }
}
