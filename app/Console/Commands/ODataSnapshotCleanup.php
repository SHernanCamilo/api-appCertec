<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Limpia snapshots de OData que ya no se usan.
 *
 * Los snapshots son archivos NDJSON en storage/app/odata_snapshots.
 * Se regeneran solos al expirar el TTL, así que borrar los antiguos
 * solo libera disco (no rompe nada).
 */
class ODataSnapshotCleanup extends Command
{
    protected $signature = 'odata:snapshot-cleanup
                            {--hours=6 : Eliminar snapshots sin acceso hace más de N horas}';

    protected $description = 'Elimina snapshots de OData antiguos para liberar disco';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dir   = storage_path('app/odata_snapshots');

        if (!is_dir($dir)) {
            $this->info('No hay snapshots que limpiar.');
            return self::SUCCESS;
        }

        $cutoff  = time() - ($hours * 3600);
        $deleted = 0;
        $freedMb = 0.0;
        $kept    = 0;

        foreach (glob($dir . '/*.ndjson') ?: [] as $file) {
            // filemtime = última generación; si expiró hace rato, nadie lo usa
            if (filemtime($file) < $cutoff) {
                $freedMb += filesize($file) / 1048576;
                @unlink($file);
                @unlink($file . '.meta');
                $deleted++;
            } else {
                $kept++;
            }
        }

        // Limpiar temporales huérfanos: .building.* (procesos que murieron a medio
        // construir) y .gz (descargas de R2 interrumpidas).
        foreach (['/*.building.*', '/*.gz'] as $pattern) {
            foreach (glob($dir . $pattern) ?: [] as $orphan) {
                if (filemtime($orphan) < time() - 1800) { // 30 min
                    $freedMb += filesize($orphan) / 1048576;
                    @unlink($orphan);
                    $deleted++;
                }
            }
        }

        // ── Protección de disco: si el total supera el límite, borrar los más viejos ──
        $maxDiskMb = (float) env('ODATA_SNAPSHOT_MAX_DISK_MB', 3072); // 3 GB default
        $this->enforceMaxDisk($dir, $maxDiskMb, $deleted, $freedMb);

        $this->info(sprintf(
            'Snapshots eliminados: %d (%.1f MB liberados) · conservados: %d',
            $deleted,
            $freedMb,
            $kept
        ));

        return self::SUCCESS;
    }

    /**
     * Si el directorio supera el límite de disco, borra los snapshots más viejos
     * (por mtime) hasta quedar debajo del límite.
     */
    private function enforceMaxDisk(string $dir, float $maxMb, int &$deleted, float &$freedMb): void
    {
        $files = [];
        $totalMb = 0.0;

        foreach (glob($dir . '/*.ndjson') ?: [] as $file) {
            $size = filesize($file);
            $totalMb += $size / 1048576;
            $files[] = ['path' => $file, 'mtime' => filemtime($file), 'size' => $size];
        }

        if ($totalMb <= $maxMb) {
            return;
        }

        // Ordenar por mtime ascendente (los más viejos primero)
        usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

        foreach ($files as $f) {
            if ($totalMb <= $maxMb) {
                break;
            }
            $sizeMb = $f['size'] / 1048576;
            @unlink($f['path']);
            @unlink($f['path'] . '.meta');
            $totalMb -= $sizeMb;
            $freedMb += $sizeMb;
            $deleted++;

            $this->line("  Disco: eliminado " . basename($f['path']) . " (" . round($sizeMb, 1) . " MB) por límite");
        }
    }
}
