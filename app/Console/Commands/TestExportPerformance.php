<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fabric\Export\StreamingExportWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Test de rendimiento del export completo: R2 → descarga → generar Excel.
 *
 * Ejecuta todo el flujo sin Horizon ni Queue: mide tiempos reales de cada fase.
 *
 * Uso:
 *   php artisan test:export-performance gd VW_Glosa_HistoricoEstadisticoGlosas_Nva
 *   php artisan test:export-performance gd VW_Portfolio_NotasCartera_AceptaGlosas
 *   php artisan test:export-performance ca VW_Portfolio_CarteraXEdades
 *
 *   Con --max-rows=1000 para probar rápido con pocas filas.
 */
class TestExportPerformance extends Command
{
    protected $signature = 'test:export-performance
                            {schema : Esquema (ej: gd, ca, rf)}
                            {view : Nombre de la vista}
                            {--max-rows=200000 : Máximo de filas a exportar}
                            {--skip-excel : Solo descargar datos, no generar el Excel}';

    protected $description = 'Test de rendimiento: R2 → descarga → generar Excel (sin Horizon)';

    public function handle(): int
    {
        $schema  = $this->argument('schema');
        $view    = $this->argument('view');
        $maxRows = (int) $this->option('max-rows');
        $format  = 'csv'; // DuckDB genera CSV con fechas ya formateadas (sin T)

        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        $this->info("═══════════════════════════════════════════════════════");
        $this->info("  TEST EXPORT: {$schema}.{$view}");
        $this->info("  Max rows: {$maxRows} | Format: csv (DuckDB directo)");
        $this->info("  URL: {$url}/api/data/export/r2");
        $this->info("═══════════════════════════════════════════════════════");
        $this->newLine();

        // ── Fase 1: Descargar de R2 ─────────────────────────────────────
        $this->info('▶ Fase 1: Descargando de R2...');
        $t1 = microtime(true);

        $response = Http::timeout(300)
            ->connectTimeout(10)
            ->withHeaders(['X-API-Key' => env('GRAPHQL_API_KEY', '')])
            ->post($url . '/api/data/export/r2', [
                'token'        => $token,
                'schema_name'  => $schema,
                'view'         => $view,
                'format'       => $format,
                'max_rows'     => $maxRows,
                'columns'      => [],
                'filters'      => new \stdClass(),
                'user_email'   => 'test@medilaser.com.co',
                'user_name'    => 'Test Export',
                'department'   => 'NAL',
                'groups'       => ['GG-BD-' . strtoupper($schema), 'GG-BD-ADMIN'],
                'ensure_fresh' => false,
            ]);

        $t1End = microtime(true);
        $downloadTime = round($t1End - $t1, 2);

        if ($response->failed()) {
            $this->error("  ✗ R2 respondió HTTP {$response->status()}");
            $this->error("  Body: " . substr($response->body(), 0, 500));

            if ($response->status() === 404) {
                $this->warn('  → Vista sin parquet en R2. Probar con /api/data/export/stream');
            }

            return self::FAILURE;
        }

        $totalRows  = (int) ($response->header('X-Total-Rows') ?? 0);
        $source     = $response->header('X-Source') ?? 'unknown';
        $elapsed    = $response->header('X-Elapsed-Ms') ?? '?';
        $bodySize   = strlen($response->body());

        $this->info("  ✓ Descarga completada");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Tiempo descarga (Laravel)', "{$downloadTime}s"],
                ['Tiempo Python (X-Elapsed-Ms)', "{$elapsed}ms"],
                ['Filas (X-Total-Rows)', number_format($totalRows)],
                ['Tamaño response', $this->humanSize($bodySize)],
                ['Source', $source],
                ['Regenerated', $response->header('X-Parquet-Regenerated') ?? 'no'],
            ]
        );

        if ($this->option('skip-excel')) {
            $this->info('  → --skip-excel: se omite la generación del archivo.');
            return self::SUCCESS;
        }

        // ── Fase 2: Guardar CSV y convertir a xlsx ──────────────────────
        $this->newLine();
        $this->info('▶ Fase 2: Guardando CSV y convirtiendo a xlsx...');
        $t2 = microtime(true);

        $dir      = storage_path('app/fabric_exports/_test_perf_' . time());
        $baseName = "{$schema}_{$view}_test";

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Guardar el gzip a disco y decodificar por STREAMING (no cargar 459 MB en RAM)
        $body = $response->body();
        $contentType = $response->header('Content-Type') ?? '';
        $isGzip = str_contains($contentType, 'gzip') || (strlen($body) >= 2 && ord($body[0]) === 0x1f && ord($body[1]) === 0x8b);

        if ($isGzip) {
            $this->info("  → Response es gzip, decodificando por streaming a disco...");
            $gzFile = "{$dir}/{$baseName}_raw.gz";
            file_put_contents($gzFile, $body);
            unset($response, $body); // Liberar RAM (~68 MB)

            $gz  = gzopen($gzFile, 'rb');
            $csv = fopen("{$dir}/{$baseName}_raw.csv", 'w');
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 65536);
                if ($chunk !== false && $chunk !== '') fwrite($csv, $chunk);
            }
            gzclose($gz);
            fclose($csv);
            @unlink($gzFile);

            $csvFile   = "{$dir}/{$baseName}_raw.csv";
            $savedSize = filesize($csvFile);
        } else {
            $csvFile = "{$dir}/{$baseName}_raw.csv";
            file_put_contents($csvFile, $body);
            $savedSize = strlen($body);
            unset($response, $body);
        }

        $this->info("  CSV guardado: {$this->humanSize($savedSize)}");

        // Convertir a xlsx usando el nuevo método fromCsvFile()
        $result = StreamingExportWriter::fromCsvFile($csvFile, $dir, $baseName, $schema, $view);

        $t2End     = microtime(true);
        $excelTime = round($t2End - $t2, 2);
        $totalTime = round($t2End - $t1, 2);

        $this->newLine();
        $this->info("  ✓ Archivo generado");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Tiempo generación', "{$excelTime}s"],
                ['Filas', number_format($result->rows)],
                ['Formato resultado', $result->format],
                ['Archivo', $result->filename],
                ['Tamaño archivo', $this->humanSize($result->bytes)],
                ['RAM pico', round(memory_get_peak_usage(true) / 1024 / 1024, 1) . ' MB'],
            ]
        );

        $this->newLine();
        $this->info("═══════════════════════════════════════════════════════");
        $this->info("  TIEMPO TOTAL: {$totalTime}s");
        $this->info("    Descarga R2:      {$downloadTime}s");
        $this->info("    Generar archivo:  {$excelTime}s");
        $this->info("  ARCHIVO: {$result->filename} ({$this->humanSize($result->bytes)})");
        $this->info("═══════════════════════════════════════════════════════");

        if ($result->filename !== '') {
            $this->line("  Archivo en: {$dir}/{$result->filename}");
            $this->line("  Para eliminarlo: rm -rf {$dir}");
        }

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
