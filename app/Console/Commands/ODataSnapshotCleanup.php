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

        $this->info(sprintf(
            'Snapshots eliminados: %d (%.1f MB liberados) · conservados: %d',
            $deleted,
            $freedMb,
            $kept
        ));

        return self::SUCCESS;
    }
}
