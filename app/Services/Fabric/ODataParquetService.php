<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy delgado al endpoint OData de Graph-Fabric que pagina el parquet con DuckDB.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ¿QUÉ CAMBIÓ Y POR QUÉ EXISTE ESTE SERVICIO?
 * ─────────────────────────────────────────────────────────────────────────────
 * El flujo viejo (ODataSnapshotService) bajaba el parquet completo, lo copiaba a
 * un NDJSON en disco y lo paginaba releyendo el archivo desde el principio en
 * cada página. La página 28 releía 560.000 líneas para descartarlas: costo
 * O(n²) sobre el total.
 *
 * Ahora Graph-Fabric expone GET /api/data/odata/{schema}/{view}, que pagina el
 * parquet directo con DuckDB (OFFSET/LIMIT casi gratis) y devuelve OData estándar.
 * Laravel deja de bajar, copiar y paginar: solo autentica, arma la query y hace
 * relay de la respuesta. La página profunda cuesta casi lo mismo que la primera
 * (medido: 72 ms la página 0, 99 ms una página con skip alto).
 *
 * Este servicio NO reemplaza a ODataSnapshotService: convive con él. La ruta
 * vieja sigue sirviendo los links OData existentes; la nueva es un carril aparte
 * para no represar lo demás.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTENTICACIÓN HACIA GRAPH
 * ─────────────────────────────────────────────────────────────────────────────
 * Graph espera el token en el QUERY STRING (no en body ni header): Excel/Power
 * Query manda GET sin body, por eso es así. `grupos` y `department` son
 * obligatorios — es el service token con contexto de usuario, igual que
 * /export/start. Aquí se usa el contexto de sistema (nacional) porque el acceso
 * del usuario final ya se validó en Laravel antes de llegar aquí.
 */
final class ODataParquetService
{
    /** Filas por página por defecto si el cliente no pide $top. */
    private const DEFAULT_TOP = 50000;

    /** Tope duro de filas por página (lo que acepta Graph). */
    private const MAX_TOP = 200000;

    /**
     * Pide una página del parquet a Graph-Fabric.
     *
     * @param  array{
     *     filters?: array<string,mixed>,
     *     columns?: list<string>,
     *     sort_col?: string,
     *     sort_dir?: string
     * } $context
     * @return array{
     *     success: bool,
     *     status: int,
     *     value?: list<array<string,mixed>>,
     *     count?: int|null,
     *     next_skip?: int|null,
     *     source?: string,
     *     returned?: int,
     *     age_min?: float|null,
     *     message?: string
     * }
     */
    public function page(
        string $schema,
        string $view,
        int $skip,
        int $top,
        array $context = [],
        bool $withCount = false,
    ): array {
        $top  = max(1, min($top > 0 ? $top : self::DEFAULT_TOP, self::MAX_TOP));
        $skip = max(0, $skip);

        $query = [
            'token'      => $this->token(),
            'grupos'     => 'GG-BD-' . strtoupper($schema) . ',GG-BD-ADMIN',
            'department' => 'NAL',
            '$top'       => $top,
            '$skip'      => $skip,
        ];

        if ($withCount) {
            $query['$count'] = 'true';
        }

        // $select: columnas específicas. Sin esto Graph devuelve todas.
        if (!empty($context['columns'])) {
            $query['$select'] = implode(',', $context['columns']);
        }

        // $orderby: Graph usa la sintaxis OData "Campo dir".
        $sortCol = (string) ($context['sort_col'] ?? '');
        if ($sortCol !== '') {
            $dir = strtolower((string) ($context['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $query['$orderby'] = "{$sortCol} {$dir}";
        }

        // $filter: Graph acepta la expresión OData cruda. Si el link trae
        // filtros propios, se pasan tal cual llegaron del controlador.
        if (!empty($context['odata_filter'])) {
            $query['$filter'] = (string) $context['odata_filter'];
        }

        try {
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->acceptJson()
                ->get($this->url("/api/data/odata/{$schema}/{$view}"), $query);
        } catch (\Throwable $e) {
            Log::warning('[ODataParquet] error de conexion', [
                'view'  => "{$schema}.{$view}",
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status'  => 503,
                'message' => 'No se pudo conectar con el servicio de datos.',
            ];
        }

        // 409 = la vista todavía no tiene parquet registrado en el scheduler.
        if ($response->status() === 409) {
            Log::info('[ODataParquet] vista sin parquet', ['view' => "{$schema}.{$view}"]);

            return [
                'success' => false,
                'status'  => 409,
                'message' => 'Esta vista aun no tiene parquet. Registrela en el scheduler y reintente en unos minutos.',
            ];
        }

        if (!$response->successful()) {
            Log::warning('[ODataParquet] respuesta no exitosa', [
                'view'   => "{$schema}.{$view}",
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'status'  => $response->status() >= 500 ? 502 : $response->status(),
                'message' => 'El servicio de datos respondio HTTP ' . $response->status() . '.',
            ];
        }

        return $this->mapResponse($response, $skip, $top);
    }

    /**
     * Traduce la respuesta OData de Graph al contrato que consume el controlador.
     *
     * @return array{success: bool, status: int, value: list<array<string,mixed>>, count: int|null, next_skip: int|null, source: string, returned: int, age_min: float|null}
     */
    private function mapResponse(Response $response, int $skip, int $top): array
    {
        $json  = $response->json() ?? [];
        $value = is_array($json['value'] ?? null) ? $json['value'] : [];

        // Graph ya arma el @odata.nextLink; aquí solo interesa saber SI hay más,
        // porque Laravel reconstruye su propio nextLink con la URL pública.
        $hasNext   = !empty($json['@odata.nextLink']);
        $nextSkip  = $hasNext ? $skip + $top : null;
        $count     = isset($json['@odata.count']) ? (int) $json['@odata.count'] : null;

        return [
            'success'   => true,
            'status'    => 200,
            'value'     => $value,
            'count'     => $count,
            'next_skip' => $nextSkip,
            'source'    => (string) ($response->header('X-Source') ?: 'parquet-local'),
            'returned'  => (int) ($response->header('X-Returned-Rows') ?: count($value)),
            'age_min'   => is_numeric($response->header('X-Parquet-Age-Min'))
                ? (float) $response->header('X-Parquet-Age-Min')
                : null,
        ];
    }

    /**
     * Filtra directamente sobre el parquet local vía DuckDB (endpoint dedicado
     * de Graph-Fabric). Filtra en el servidor (~90 ms) sin traer la vista
     * completa a memoria.
     *
     * Formatos de filtro soportados por el endpoint:
     *   ['Placa' => '021106']                  → igualdad exacta
     *   ['Responsable' => '%CABRERA%']          → parcial ILIKE (case-insensitive)
     *   ['Fecha' => ['2026-01-01','2026-01-31']]→ rango BETWEEN
     *   ['Estado' => ['Confirmado','Baja']]     → lista IN
     *
     * @param  array<string, mixed> $filters
     * @param  array{columns?: list<string>, sort_col?: string, sort_dir?: string, count?: bool} $opciones
     * @return array{
     *     success: bool, status: int, value?: list<array<string,mixed>>,
     *     count?: int|null, returned?: int, has_next?: bool,
     *     source?: string, elapsed_ms?: int|null, message?: string
     * }
     */
    public function filter(
        string $schema,
        string $view,
        array $filters,
        int $limit = 50,
        int $offset = 0,
        array $opciones = []
    ): array {
        $query = [
            'token'      => $this->token(),
            'grupos'     => 'GG-BD-' . strtoupper($schema) . ',GG-BD-ADMIN',
            'department' => 'NAL',
            'filters'    => json_encode($filters === [] ? new \stdClass() : $filters),
            'limit'      => max(1, min($limit, self::MAX_TOP)),
            'offset'     => max(0, $offset),
            'count'      => ($opciones['count'] ?? true) ? 'true' : 'false',
        ];

        if (!empty($opciones['columns'])) {
            $query['columns'] = implode(',', $opciones['columns']);
        }
        if (!empty($opciones['sort_col'])) {
            $query['sort_col'] = (string) $opciones['sort_col'];
            $query['sort_dir'] = strtolower((string) ($opciones['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        }

        try {
            $response = Http::timeout(60)
                ->connectTimeout(10)
                ->acceptJson()
                ->get($this->url("/api/data/parquet-filter/{$schema}/{$view}"), $query);
        } catch (\Throwable $e) {
            Log::warning('[ODataParquet] filter: error de conexion', [
                'view'  => "{$schema}.{$view}",
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'status' => 503, 'message' => 'No se pudo conectar con el servicio de datos.'];
        }

        // 409 = la vista aún no tiene parquet local generado.
        if ($response->status() === 409) {
            return ['success' => false, 'status' => 409, 'message' => 'La vista aún no tiene parquet local.'];
        }

        if (!$response->successful()) {
            Log::warning('[ODataParquet] filter: respuesta no exitosa', [
                'view'   => "{$schema}.{$view}",
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);

            return [
                'success' => false,
                'status'  => $response->status() >= 500 ? 502 : $response->status(),
                'message' => 'El servicio de datos respondió HTTP ' . $response->status() . '.',
            ];
        }

        $json = $response->json() ?? [];

        return [
            'success'    => (bool) ($json['success'] ?? false),
            'status'     => 200,
            'value'      => is_array($json['value'] ?? null) ? $json['value'] : [],
            'count'      => isset($json['count']) ? (int) $json['count'] : null,
            'returned'   => (int) ($json['returned'] ?? count($json['value'] ?? [])),
            'has_next'   => (bool) ($json['has_next'] ?? false),
            'source'     => (string) ($json['source'] ?? 'parquet-local'),
            'elapsed_ms' => isset($json['elapsed_ms']) ? (int) $json['elapsed_ms'] : null,
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/') . $path;
    }

    private function token(): string
    {
        return (string) (config('fabric.token_admin') ?: config('fabric.api_key', ''));
    }
}
