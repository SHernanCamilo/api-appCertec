<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Servicio de cache en Cloudflare R2 para vistas de Fabric.
 *
 * Genera CSV.gz comprimido por vista y lo sube a R2.
 * Excel/Power Query descarga directo desde R2 (CDN, sin pasar por la VPS).
 * Se regenera cada hora con cron y limpia archivos viejos.
 */
class R2CacheService
{
    private string $prefix;
    private int $maxSizeGb;

    public function __construct()
    {
        $this->prefix = env('R2_FABRIC_PREFIX', 'fabric-cache');
        $this->maxSizeGb = (int) env('R2_MAX_SIZE_GB', 8);
    }

    /**
     * Genera CSV.gz de una vista y lo sube a R2.
     * Retorna la URL pública del archivo.
     */
    public function generateAndUpload(string $schema, string $view, array $filters = []): ?array
    {
        $url     = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token   = env('TOKEN_ADMIN', '');
        $limit   = 10000;
        $offset  = 0;
        $maxRows = 500000;
        $totalRows = 0;

        // Nombre del archivo en R2
        $timestamp = now()->format('Ymd_H');
        $filterHash = md5(json_encode($filters));
        $filename = "{$this->prefix}/{$schema}/{$view}_{$timestamp}_{$filterHash}.csv.gz";

        // Archivo temporal local
        $tmpPath = storage_path("app/r2_tmp_{$schema}_{$view}.csv");
        $handle = fopen($tmpPath, 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8

        $headers = [];
        $payload = [
            'token'       => $token,
            'groups'      => ['GG-BD-' . strtoupper($schema), 'GG-BD-ADMIN'],
            'department'  => 'NAL',
            'user_email'  => 'sistema@medilaser.com.co',
            'user_name'   => 'R2 Cache Generator',
            'schema_name' => $schema,
            'view'        => $view,
            'columns'     => [],
            'filters'     => empty($filters) ? new \stdClass() : $filters,
            'sort_col'    => '',
            'sort_dir'    => 'asc',
            'skip_count'  => true,
        ];

        while ($offset < $maxRows) {
            $payload['limit'] = $limit;
            $payload['offset'] = $offset;

            $response = Http::timeout(130)->connectTimeout(10)->acceptJson()
                ->post($url . '/api/data/dynamic', $payload);

            if ($response->failed()) break;

            $data = $response->json();
            $items = $data['items'] ?? [];
            if (empty($items)) break;

            if (empty($headers)) {
                $headers = array_keys($items[0]);
                fputcsv($handle, $headers, ';');
            }

            foreach ($items as $row) {
                $values = array_map(function ($h) use ($row) {
                    $v = $row[$h] ?? '';
                    return is_string($v) ? str_replace(["\r\n", "\n", "\r"], ' ', $v) : $v;
                }, $headers);
                fputcsv($handle, $values, ';');
                $totalRows++;
            }

            $offset += $limit;
            if (!($data['page_info']['has_next'] ?? false)) break;
        }

        fclose($handle);

        if ($totalRows === 0) {
            @unlink($tmpPath);
            return null;
        }

        // Comprimir a gzip
        $gzPath = $tmpPath . '.gz';
        $this->gzipFile($tmpPath, $gzPath);
        @unlink($tmpPath);

        // Subir a R2
        $fileSize = filesize($gzPath);
        $content = file_get_contents($gzPath);

        try {
            Storage::disk('r2')->put($filename, $content, [
                'visibility' => 'public',
                'ContentType' => 'text/csv',
                'ContentEncoding' => 'gzip',
            ]);

            @unlink($gzPath);

            Log::info('R2CacheService: archivo subido', [
                'schema' => $schema,
                'view' => $view,
                'rows' => $totalRows,
                'size' => $fileSize,
                'path' => $filename,
            ]);

            return [
                'path'      => $filename,
                'rows'      => $totalRows,
                'size'      => $fileSize,
                'size_human' => $this->humanSize($fileSize),
                'url'       => Storage::disk('r2')->url($filename),
                'generated' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            @unlink($gzPath);
            Log::error('R2CacheService: error subiendo a R2', [
                'error' => $e->getMessage(),
                'schema' => $schema,
                'view' => $view,
            ]);
            return null;
        }
    }

    /**
     * Obtener URL del cache en R2 para una vista (si existe y es reciente).
     */
    public function getCachedUrl(string $schema, string $view): ?string
    {
        $timestamp = now()->format('Ymd_H');
        $pattern = "{$this->prefix}/{$schema}/{$view}_{$timestamp}_";

        // Buscar archivos que coincidan con la hora actual
        $files = Storage::disk('r2')->files("{$this->prefix}/{$schema}");
        foreach ($files as $file) {
            if (str_contains($file, "{$view}_{$timestamp}_")) {
                return Storage::disk('r2')->url($file);
            }
        }

        return null;
    }

    /**
     * Limpiar archivos viejos de R2 (>2 horas).
     * Retorna cuántos archivos se eliminaron.
     */
    public function cleanup(): array
    {
        $deleted = 0;
        $freedBytes = 0;
        $currentHour = now()->format('Ymd_H');
        $prevHour = now()->subHour()->format('Ymd_H');

        try {
            $directories = Storage::disk('r2')->directories($this->prefix);

            foreach ($directories as $dir) {
                $files = Storage::disk('r2')->files($dir);
                foreach ($files as $file) {
                    // Mantener solo archivos de la hora actual y anterior
                    if (!str_contains($file, $currentHour) && !str_contains($file, $prevHour)) {
                        $size = Storage::disk('r2')->size($file);
                        Storage::disk('r2')->delete($file);
                        $deleted++;
                        $freedBytes += $size;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('R2CacheService cleanup error', ['error' => $e->getMessage()]);
        }

        return ['deleted' => $deleted, 'freed' => $this->humanSize($freedBytes)];
    }

    /**
     * Obtener uso total de R2 (aproximado).
     */
    public function getUsage(): array
    {
        $totalSize = 0;
        $fileCount = 0;

        try {
            $directories = Storage::disk('r2')->directories($this->prefix);
            foreach ($directories as $dir) {
                $files = Storage::disk('r2')->files($dir);
                foreach ($files as $file) {
                    $totalSize += Storage::disk('r2')->size($file);
                    $fileCount++;
                }
            }
        } catch (\Exception $e) {
            // Silenciar
        }

        return [
            'files' => $fileCount,
            'size' => $totalSize,
            'size_human' => $this->humanSize($totalSize),
            'max_gb' => $this->maxSizeGb,
            'usage_percent' => $this->maxSizeGb > 0
                ? round($totalSize / ($this->maxSizeGb * 1073741824) * 100, 1)
                : 0,
        ];
    }

    private function gzipFile(string $source, string $dest): void
    {
        $fp = gzopen($dest, 'wb6');
        $in = fopen($source, 'rb');
        while (!feof($in)) {
            gzwrite($fp, fread($in, 65536));
        }
        fclose($in);
        gzclose($fp);
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
