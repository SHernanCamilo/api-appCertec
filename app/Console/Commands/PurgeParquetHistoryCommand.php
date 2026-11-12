<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Vacia el historial de parquets para empezar a medir desde cero (barrido de las 04:00).
 *
 * Seguridad:
 *   - Sin --apply solo muestra cuantas filas hay. No toca nada.
 *   - Con --apply guarda ANTES un respaldo completo (CSV comprimido gzip) en
 *     storage/app/backups/ y comprueba que tenga todas las filas. Solo entonces
 *     vacia la tabla (TRUNCATE). Si el respaldo no cuadra, no borra nada.
 *
 * Uso:
 *   php artisan fabric:purge-parquet-history
 *   php artisan fabric:purge-parquet-history --apply
 *   php artisan fabric:purge-parquet-history --apply --incidents   (tambien bi_parquet_incidents)
 */
class PurgeParquetHistoryCommand extends Command
{
    protected $signature = 'fabric:purge-parquet-history
        {--apply : Respalda y vacia (sin esto solo muestra el conteo)}
        {--incidents : Vacia tambien bi_parquet_incidents}';

    protected $description = 'Respalda y vacia bi_parquet_history (y opcionalmente bi_parquet_incidents)';

    public function handle(): int
    {
        $tablas = ['bi_parquet_history'];
        if ($this->option('incidents')) {
            $tablas[] = 'bi_parquet_incidents';
        }

        foreach ($tablas as $t) {
            if (! Schema::hasTable($t)) {
                $this->warn("La tabla {$t} no existe; se omite.");
                continue;
            }

            $n = DB::table($t)->count();
            $rango = $t === 'bi_parquet_history'
                ? DB::table($t)->selectRaw('MIN(captured_at) a, MAX(captured_at) b')->first()
                : DB::table($t)->selectRaw('MIN(created_at) a, MAX(created_at) b')->first();
            $this->line(sprintf('%s: %s filas (%s a %s)', $t, number_format($n), $rango->a ?? '-', $rango->b ?? '-'));

            if (! $this->option('apply')) {
                continue;
            }

            if ($n === 0) {
                $this->info("  {$t} ya esta vacia.");
                continue;
            }

            $path = $this->backup($t);
            $guardadas = $this->countBackupRows($path);
            if ($guardadas !== $n) {
                $this->error("  El respaldo de {$t} tiene {$guardadas} filas de {$n}. NO se vacia la tabla.");

                return 1;
            }
            $this->info("  Respaldo verificado: {$path} ({$guardadas} filas)");

            DB::table($t)->truncate();
            $this->info("  {$t} vaciada.");
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN: no se cambio nada. Ejecute con --apply para respaldar y vaciar.');
        }

        return 0;
    }

    /** CSV comprimido con todas las filas, leido por lotes para no cargar la tabla en memoria. */
    private function backup(string $tabla): string
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $path = "{$dir}/{$tabla}_" . now()->format('Ymd_His') . '.csv.gz';

        $gz = gzopen($path, 'wb6');
        $cabecera = false;

        DB::table($tabla)->orderBy('id')->chunkById(5000, function ($filas) use ($gz, &$cabecera): void {
            foreach ($filas as $f) {
                $f = (array) $f;
                if (! $cabecera) {
                    gzwrite($gz, $this->csvLine(array_keys($f)));
                    $cabecera = true;
                }
                gzwrite($gz, $this->csvLine(array_values($f)));
            }
        });

        gzclose($gz);

        return $path;
    }

    private function countBackupRows(string $path): int
    {
        $gz = gzopen($path, 'rb');
        $lineas = 0;
        while (! gzeof($gz)) {
            if (gzgets($gz) !== false) {
                $lineas++;
            }
        }
        gzclose($gz);

        return max(0, $lineas - 1); // sin la cabecera
    }

    /** @param  array<int, mixed>  $valores */
    private function csvLine(array $valores): string
    {
        $h = fopen('php://memory', 'w+');
        fputcsv($h, array_map(fn ($v) => $v === null ? '' : str_replace(["\r", "\n"], ' ', (string) $v), $valores));
        rewind($h);
        $linea = (string) stream_get_contents($h);
        fclose($h);

        return $linea;
    }
}
