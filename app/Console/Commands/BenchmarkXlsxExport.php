<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fabric\Export\StreamingExportWriter;
use Illuminate\Console\Command;

/**
 * Mide, por consola, cuánto tarda armar el Excel a partir del .ndjson.gz de un
 * export. Aísla la fase de conversión (que era la sospechosa de la demora) del
 * polling y del navegador.
 *
 * El .gz lo deja el job en storage/app/fabric_exports/<jobId>/download.gz, pero
 * se borra tras enviarse. Lo práctico es copiarlo antes, o apuntar a uno propio.
 *
 * Uso:
 *   php artisan fabric:bench-xlsx --gz=storage/app/fabric_exports/ab12/download.gz
 *   php artisan fabric:bench-xlsx --gz=/tmp/bench.gz --rows=567740 --keep
 */
final class BenchmarkXlsxExport extends Command
{
    protected $signature = 'fabric:bench-xlsx
        {--gz=    : Ruta a un .ndjson.gz descargado del export}
        {--rows=0 : Total de filas conocido (para elegir el camino; 0 = auto)}
        {--keep   : No borrar el .xlsx generado al terminar}';

    protected $description = 'Mide cuánto tarda armar el Excel desde el .ndjson.gz de un export';

    public function handle(): int
    {
        $gzPath = (string) $this->option('gz');
        $rows   = (int) $this->option('rows');

        if ($gzPath === '') {
            $this->error('Indique la ruta al .gz con --gz=<ruta>.');
            $this->newLine();
            $this->line('¿No tiene el .gz? Lance el export desde la web y, antes de que termine,');
            $this->line('copie:  cp storage/app/fabric_exports/<jobId>/download.gz /tmp/bench.gz');
            $this->line('Luego:  php artisan fabric:bench-xlsx --gz=/tmp/bench.gz');

            return self::FAILURE;
        }

        if (!is_file($gzPath)) {
            $this->error("No existe el archivo: {$gzPath}");

            return self::FAILURE;
        }

        $this->line("Archivo: {$gzPath} (" . $this->human((int) filesize($gzPath)) . ')');
        $this->line('rowHint: ' . ($rows > 0 ? number_format($rows) : 'auto'));
        $this->newLine();

        $dir = dirname($gzPath);

        $t0     = microtime(true);
        $result = StreamingExportWriter::fromNdjsonGzFile($gzPath, $dir, 'bench_prod', 'dc', 'VW_Benchmark', $rows);
        $secs   = microtime(true) - $t0;

        $this->line('── Resultado ──────────────────────────────────────────');
        $this->info(sprintf(
            'Conversión: %.1f s → %s (%s filas, %s)',
            $secs,
            $result->format,
            number_format($result->rows),
            $this->human($result->bytes)
        ));
        $this->line(sprintf('Velocidad : %s filas/s', number_format((int) ($result->rows / max(0.001, $secs)))));
        $this->line('RAM pico  : ' . $this->human(memory_get_peak_usage(true)));

        if ($result->isEmpty()) {
            $this->warn('El writer no devolvió filas. Revise que el .gz sea NDJSON válido.');
        }

        if (!$this->option('keep') && is_file($result->path)) {
            @unlink($result->path);
            $this->line('(.xlsx de prueba borrado; use --keep para conservarlo)');
        } elseif (is_file($result->path)) {
            $this->line("Archivo   : {$result->path}");
        }

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = (float) $bytes;
        $i     = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
