<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use App\Jobs\ODataSnapshotRefreshJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot service para OData — evita golpear Fabric en cada página de Excel.
 *
 * PROBLEMA:
 *   Excel/Power Query pagina (skip=0, skip=20000, skip=40000...). Con la caché
 *   por página, cada request era un cache miss → golpe a Fabric. Para 626K filas
 *   son ~32 consultas a Fabric por cada refresco de Excel.
 *
 * SOLUCIÓN — Snapshot en disco + stale-while-revalidate:
 *
 *   1) Primera petición (no hay snapshot): se descarga el dataset COMPLETO una
 *      sola vez —preferente desde el parquet de R2, que no consume Fabric— y se
 *      guarda como NDJSON. Es la única petición que espera.
 *
 *   2) Snapshot fresco (edad < TTL): se sirve del disco. Cero Fabric.
 *
 *   3) Snapshot vencido (edad >= TTL): se sirve el snapshot actual AL INSTANTE y
 *      se despacha ODataSnapshotRefreshJob para regenerarlo en background. Excel
 *      nunca espera y la siguiente lectura ya trae datos nuevos. Este es el
 *      patrón stale-while-revalidate que usan los CDN (Cloudflare, Fastly,
 *      Google Cloud CDN) para servir rápido sin quedarse en datos viejos.
 *
 *   4) Snapshot demasiado viejo (edad >= MAX_AGE, p. ej. la cola de Horizon está
 *      caída): se regenera de forma bloqueante. Es la red de seguridad para que
 *      nunca se sirva algo indefinidamente desactualizado.
 *
 * VALIDACIÓN DE FRESCURA (para no bajar la misma data dos veces):
 *   El job de refresco primero pregunta a Fabric cuántas filas tiene la vista
 *   (COUNT, consulta barata). Si coincide con el snapshot Y el último rebuild
 *   real es más reciente que MAX_AGE, solo se renueva la marca de tiempo:
 *   `unchanged`, cero descarga. Si el conteo cambió o el rebuild real ya es
 *   viejo, se descarga completo. Así los INSERT se detectan de inmediato y los
 *   UPDATE sin cambio de conteo quedan acotados a MAX_AGE.
 *
 * Consistencia de paginación:
 *   El refresco solo se dispara en la página 0. Mientras Excel recorre las
 *   páginas 1..N el archivo no se reemplaza, así que no se saltan ni se
 *   duplican filas a mitad del recorrido.
 */
final class ODataSnapshotService
{
    /** Directorio base de snapshots dentro de storage/app. */
    private const DIR = 'odata_snapshots';

    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {}

    /**
     * Devuelve una página del snapshot aplicando stale-while-revalidate.
     *
     * @param  string $linkCode  Código del link OData (identifica el dataset)
     * @param  array  $context   ['schema' => , 'view' => , 'filters' => , 'columns' => ,
     *                            'sort_col' => , 'sort_dir' => , 'max_rows' => ]
     * @param  int    $skip      Offset solicitado por Excel
     * @param  int    $top       Cantidad de filas solicitadas
     * @param  int    $ttl       Segundos de validez del snapshot
     * @return array{success: bool, data: array, total: int, has_next: bool, source: string, age: int, stale: bool, message?: string}
     */
    public function getPage(
        string $linkCode,
        array $context,
        int $skip,
        int $top,
        int $ttl
    ): array {
        $path   = $this->snapshotPath($linkCode, $context);
        $exists = is_file($path);
        $age    = $exists ? (time() - (int) filemtime($path)) : PHP_INT_MAX;
        $maxAge = $this->maxAge($ttl);

        // Caso 1 y 4: no existe, o está tan viejo que no es aceptable servirlo.
        if (!$exists || $age >= $maxAge) {
            $built = $this->build($path, $context);

            if (!$built['success']) {
                // Si había un snapshot viejo, es mejor servirlo que devolver error.
                if ($exists) {
                    Log::warning('ODataSnapshot: rebuild falló, se sirve el snapshot viejo', [
                        'link' => $linkCode,
                        'age'  => $age,
                    ]);
                    return $this->readPage($path, $skip, $top) + ['age' => $age, 'stale' => true];
                }

                return [
                    'success'  => false,
                    'data'     => [],
                    'total'    => 0,
                    'has_next' => false,
                    'source'   => 'none',
                    'age'      => 0,
                    'stale'    => false,
                    'message'  => $built['message'] ?? 'No se pudo generar el snapshot.',
                ];
            }

            return $this->readPage($path, $skip, $top) + ['age' => 0, 'stale' => false];
        }

        // Caso 3: vencido pero utilizable → servir ya y refrescar en background.
        $stale = $age >= $ttl;
        if ($stale && $skip === 0) {
            $this->queueRefresh($linkCode, $context, $ttl);
        }

        // Caso 2 y 3: lectura directa de disco.
        return $this->readPage($path, $skip, $top) + ['age' => $age, 'stale' => $stale];
    }

    /**
     * Regenera el snapshot. Lo invoca ODataSnapshotRefreshJob en background.
     *
     * Primero valida el conteo contra Fabric: si no cambió (y el último rebuild
     * real no es demasiado viejo), no vuelve a descargar el dataset — solo
     * refresca la marca de frescura.
     *
     * @return array{rows: int, source: string}
     */
    public function refresh(string $linkCode, array $context, bool $force = false): array
    {
        $path = $this->snapshotPath($linkCode, $context);

        if (!$force && is_file($path)) {
            $verdict = $this->tryRevalidateByCount($path, $context);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        $built = $this->build($path, $context);

        if (!$built['success']) {
            throw new \RuntimeException($built['message'] ?? 'Rebuild de snapshot falló.');
        }

        return ['rows' => (int) $built['rows'], 'source' => (string) $built['source']];
    }

    /**
     * Invalida el snapshot de un link (p. ej. cuando cambian sus filtros).
     */
    public function invalidate(string $linkCode): void
    {
        $dir = storage_path('app/' . self::DIR);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/' . $linkCode . '_*.ndjson') ?: [] as $file) {
            @unlink($file);
            @unlink($file . '.meta');
        }
    }

    // =========================================================================
    // REVALIDACIÓN POR CONTEO
    // =========================================================================

    /**
     * Compara el conteo remoto con el del snapshot.
     *
     * @return array{rows: int, source: string}|null  null = hay que reconstruir
     */
    private function tryRevalidateByCount(string $path, array $context): ?array
    {
        if (!filter_var(env('ODATA_SNAPSHOT_COUNT_CHECK', true), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $meta  = $this->readMeta($path);
        $local = (int) ($meta['rows'] ?? 0);
        if ($local <= 0) {
            return null;
        }

        // Cota de seguridad: los UPDATE no cambian el conteo. Si el último
        // rebuild REAL ya es viejo, se reconstruye aunque el conteo coincida.
        $builtAt = isset($meta['built_at']) ? strtotime((string) $meta['built_at']) : 0;
        $hardAge = (int) env('ODATA_SNAPSHOT_REBUILD_EVERY', 3600);
        if ($builtAt <= 0 || (time() - $builtAt) >= $hardAge) {
            return null;
        }

        $remote = $this->remoteRowCount($context);
        if ($remote === null || $remote <= 0 || $remote !== $local) {
            return null;
        }

        // Sin cambios: solo renovar frescura (no se descarga nada).
        @touch($path);
        $meta['verified_at'] = now()->toIso8601String();
        @file_put_contents($path . '.meta', json_encode($meta));

        Log::info('ODataSnapshot revalidado sin cambios', [
            'schema' => $context['schema'] ?? null,
            'view'   => $context['view'] ?? null,
            'rows'   => $local,
        ]);

        return ['rows' => $local, 'source' => 'unchanged'];
    }

    /**
     * Conteo real de filas en Fabric (consulta barata: limit 1, se lee el total).
     */
    private function remoteRowCount(array $context): ?int
    {
        try {
            $result = $this->gateway->queryAsSystem(
                (string) ($context['schema'] ?? ''),
                (string) ($context['view'] ?? ''),
                [
                    'columns' => $context['columns'] ?? [],
                    'filters' => $context['filters'] ?? [],
                    'limit'   => 1,
                    'offset'  => 0,
                ]
            );

            if (!($result['success'] ?? false)) {
                return null;
            }

            $total = $result['meta']['total'] ?? null;

            return is_numeric($total) ? (int) $total : null;
        } catch (\Throwable $e) {
            Log::info('ODataSnapshot: no se pudo obtener el conteo remoto', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // CONSTRUCCIÓN DEL SNAPSHOT
    // =========================================================================

    /**
     * Descarga el dataset completo y lo escribe como NDJSON (una fila por línea).
     * Intenta primero el parquet de R2; si no está disponible, cae a Fabric paginado.
     */
    private function build(string $path, array $context): array
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'message' => 'No se pudo crear el directorio de snapshots.'];
        }

        // Escribir a un archivo temporal y renombrar al final (escritura atómica:
        // evita que otra petición lea un snapshot a medio construir).
        $tmp = $path . '.building.' . getmypid();

        $rows = $this->fetchFromR2($tmp, $context);
        $source = 'r2';

        if ($rows === null) {
            $rows = $this->fetchFromFabric($tmp, $context);
            $source = 'fabric';
        }

        if ($rows === null) {
            @unlink($tmp);
            return ['success' => false, 'message' => 'No se pudo obtener el dataset.'];
        }

        // Publicar el snapshot de forma atómica
        @rename($tmp, $path);
        file_put_contents($path . '.meta', json_encode([
            'rows'         => $rows,
            'source'       => $source,
            'built_at'     => now()->toIso8601String(),
            'verified_at'  => now()->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ]));

        Log::info('ODataSnapshot generado', [
            'schema' => $context['schema'] ?? null,
            'view'   => $context['view'] ?? null,
            'rows'   => $rows,
            'source' => $source,
        ]);

        return ['success' => true, 'rows' => $rows, 'source' => $source];
    }

    /**
     * Intenta poblar el snapshot desde el parquet de R2 (no consume Fabric).
     * Devuelve el número de filas escritas, o null si R2 no tiene el parquet.
     */
    private function fetchFromR2(string $tmp, array $context): ?int
    {
        // Con filtros no se puede usar el parquet: es un snapshot de la vista
        // completa y no garantiza el mismo resultado que Fabric filtrado.
        if (!empty($context['filters'])) {
            return null;
        }

        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        try {
            $response = Http::timeout(180)
                ->connectTimeout(10)
                ->post($url . '/api/data/export/r2', [
                    'token'       => $token,
                    'user_email'  => env('NOTIF_ADMIN_EMAIL', 'sistema@medilaser.com.co'),
                    'user_name'   => 'OData Snapshot',
                    'department'  => 'NAL-TIC NAL',
                    'groups'      => ['GG-BD-' . strtoupper($context['schema'] ?? ''), 'GG-BD-ADMIN'],
                    'schema_name' => $context['schema'] ?? '',
                    'view'        => $context['view'] ?? '',
                    'filters'     => new \stdClass(),
                    'columns'     => $context['columns'] ?? [],
                    'max_rows'    => (int) ($context['max_rows'] ?? 1000000),
                    'format'      => 'gzip',
                ]);

            // 200 = parquet disponible. Cualquier otro código → fallback a Fabric.
            if ($response->status() !== 200) {
                return null;
            }

            // El cuerpo es NDJSON comprimido: descomprimir por streaming a disco
            $gzPath = $tmp . '.gz';
            file_put_contents($gzPath, $response->body());
            unset($response);

            $gz  = gzopen($gzPath, 'rb');
            $out = fopen($tmp, 'w');
            if (!$gz || !$out) {
                @unlink($gzPath);
                return null;
            }

            $count = 0;
            while (!gzeof($gz)) {
                $line = gzgets($gz, 1048576);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                fwrite($out, rtrim($line, "\r\n") . "\n");
                $count++;
            }

            gzclose($gz);
            fclose($out);
            @unlink($gzPath);

            return $count;
        } catch (\Throwable $e) {
            Log::info('ODataSnapshot: R2 no disponible, usando Fabric', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Puebla el snapshot consultando Fabric paginado (fallback).
     * Devuelve el número de filas escritas, o null si falló.
     */
    private function fetchFromFabric(string $tmp, array $context): ?int
    {
        $out = fopen($tmp, 'w');
        if (!$out) {
            return null;
        }

        $chunk    = (int) env('ODATA_SNAPSHOT_CHUNK', 20000);
        $maxRows  = (int) ($context['max_rows'] ?? 1000000);
        $pauseMs  = (int) env('ODATA_SNAPSHOT_PAUSE_MS', 200);
        $offset   = 0;
        $total    = 0;

        while ($offset < $maxRows) {
            $result = $this->gateway->queryAsSystem(
                (string) ($context['schema'] ?? ''),
                (string) ($context['view'] ?? ''),
                [
                    'columns'  => $context['columns'] ?? [],
                    'filters'  => $context['filters'] ?? [],
                    'limit'    => $chunk,
                    'offset'   => $offset,
                    'sort_col' => $context['sort_col'] ?? '',
                    'sort_dir' => $context['sort_dir'] ?? 'asc',
                ]
            );

            if (!($result['success'] ?? false)) {
                fclose($out);
                return $total > 0 ? $total : null;
            }

            $items = $result['data'] ?? [];
            if (empty($items)) {
                break;
            }

            foreach ($items as $row) {
                fwrite($out, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
                $total++;
            }

            if (count($items) < $chunk) {
                break; // última página
            }

            $offset += $chunk;

            // Ceder el worker de Python entre lotes
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        fclose($out);
        return $total;
    }

    // =========================================================================
    // LECTURA
    // =========================================================================

    /**
     * Lee una página del snapshot sin cargar el archivo completo en memoria.
     */
    private function readPage(string $path, int $skip, int $top): array
    {
        if (!is_file($path)) {
            return ['success' => false, 'data' => [], 'total' => 0, 'has_next' => false, 'source' => 'none'];
        }

        $meta   = $this->readMeta($path);
        $total  = (int) ($meta['rows'] ?? 0);
        $source = (string) ($meta['source'] ?? 'snapshot');

        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['success' => false, 'data' => [], 'total' => $total, 'has_next' => false, 'source' => $source];
        }

        $items = [];
        $index = 0;

        while (($line = fgets($handle)) !== false) {
            if ($index >= $skip + $top) {
                break;
            }
            if ($index >= $skip) {
                $row = json_decode(trim($line), true);
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
            $index++;
        }

        fclose($handle);

        return [
            'success'  => true,
            'data'     => $items,
            'total'    => $total,
            'has_next' => ($skip + count($items)) < $total,
            'source'   => $source,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Despacha el refresco en background, con enfriamiento para no encolar
     * el mismo dataset una y otra vez si Excel reintenta.
     */
    private function queueRefresh(string $linkCode, array $context, int $ttl): void
    {
        $lockKey = 'odata_snap_refresh:' . $linkCode . ':' . $this->fingerprint($context);

        // add() es atómico: solo el primero en llegar despacha.
        if (!Cache::add($lockKey, 1, max(60, (int) ($ttl / 2)))) {
            return;
        }

        try {
            ODataSnapshotRefreshJob::dispatch($linkCode, $context);
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            Log::warning('ODataSnapshot: no se pudo encolar el refresco', [
                'link'  => $linkCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Edad máxima tolerable antes de reconstruir de forma bloqueante.
     * Red de seguridad para cuando la cola de Horizon está caída.
     */
    private function maxAge(int $ttl): int
    {
        $configured = (int) env('ODATA_SNAPSHOT_MAX_AGE', 0);

        return $configured > 0
            ? max($ttl, $configured)
            : max($ttl * 6, 3600);
    }

    /**
     * Ruta del snapshot. Incluye un hash del contexto para que distintos
     * filtros/columnas/orden no compartan archivo.
     */
    private function snapshotPath(string $linkCode, array $context): string
    {
        return storage_path('app/' . self::DIR . '/' . $linkCode . '_' . $this->fingerprint($context) . '.ndjson');
    }

    private function fingerprint(array $context): string
    {
        return md5((string) json_encode([
            $context['schema']   ?? '',
            $context['view']     ?? '',
            $context['filters']  ?? [],
            $context['columns']  ?? [],
            $context['sort_col'] ?? '',
            $context['sort_dir'] ?? '',
        ]));
    }

    private function readMeta(string $path): array
    {
        $metaPath = $path . '.meta';
        if (!is_file($metaPath)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($metaPath), true);
        return is_array($raw) ? $raw : [];
    }
}
