<?php

declare(strict_types=1);

namespace App\Services\Fabric;

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
 * SOLUCIÓN — Snapshot en disco:
 *   1ª petición → descarga el dataset COMPLETO una sola vez (preferente desde
 *   el parquet de R2, que no consume Fabric) y lo guarda como NDJSON en disco.
 *   Las siguientes páginas se sirven leyendo ese archivo por líneas.
 *
 *   Frescura por TTL: el snapshot se considera válido durante N segundos.
 *   Al expirar, la siguiente petición lo regenera. NO se valida contra Fabric
 *   en la ruta de lectura (eso es trabajo del cron de R2 en background).
 *
 * Ventajas:
 *   - 1 consulta por ventana de TTL en vez de 32 por refresco
 *   - Memoria constante: se lee línea por línea, no se carga todo en RAM
 *   - Si R2 tiene el parquet, cero carga para Fabric
 */
final class ODataSnapshotService
{
    /** Directorio base de snapshots dentro de storage/app. */
    private const DIR = 'odata_snapshots';

    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {}

    /**
     * Devuelve una página del snapshot. Lo genera si no existe o está vencido.
     *
     * @param  string $linkCode  Código del link OData (identifica el dataset)
     * @param  array  $context   ['schema' => , 'view' => , 'filters' => , 'columns' => ,
     *                            'sort_col' => , 'sort_dir' => , 'max_rows' => ]
     * @param  int    $skip      Offset solicitado por Excel
     * @param  int    $top       Cantidad de filas solicitadas
     * @param  int    $ttl       Segundos de validez del snapshot
     * @return array{success: bool, data: array, total: int, has_next: bool, source: string, message?: string}
     */
    public function getPage(
        string $linkCode,
        array $context,
        int $skip,
        int $top,
        int $ttl
    ): array {
        $path = $this->snapshotPath($linkCode, $context);

        if (!$this->isFresh($path, $ttl)) {
            $built = $this->build($path, $context);
            if (!$built['success']) {
                return [
                    'success' => false,
                    'data'    => [],
                    'total'   => 0,
                    'has_next'=> false,
                    'source'  => 'none',
                    'message' => $built['message'] ?? 'No se pudo generar el snapshot.',
                ];
            }
        }

        return $this->readPage($path, $skip, $top);
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
     * Ruta del snapshot. Incluye un hash del contexto para que distintos
     * filtros/columnas/orden no compartan archivo.
     */
    private function snapshotPath(string $linkCode, array $context): string
    {
        $fingerprint = md5(json_encode([
            $context['schema']   ?? '',
            $context['view']     ?? '',
            $context['filters']  ?? [],
            $context['columns']  ?? [],
            $context['sort_col'] ?? '',
            $context['sort_dir'] ?? '',
        ]));

        return storage_path('app/' . self::DIR . '/' . $linkCode . '_' . $fingerprint . '.ndjson');
    }

    private function isFresh(string $path, int $ttl): bool
    {
        if (!is_file($path)) {
            return false;
        }
        return (time() - filemtime($path)) < $ttl;
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
