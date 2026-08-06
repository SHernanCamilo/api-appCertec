<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Limpia los archivos de export (xlsx, csv) que tienen más de N horas.
 *
 * Los exports se generan en storage/app/fabric_exports/{job_id}/ y pesan
 * entre 4 MB y 450 MB. Sin limpieza, el disco se llena rápido.
 *
 * Programar en el scheduler:
 *   $schedule->command('exports:cleanup --hours=2')->hourly();
 */
class CleanupFabricExports extends Command
{
    protected $signature = 'exports:cleanup
                            {--hours=2 : Eliminar exports más viejos que N horas}
                            {--dry-run : Mostrar qué se eliminaría sin borrar}';

    protected $description = 'Elimina archivos de export antiguos de storage/app/fabric_exports/';

    public function handle(): int
    {
        $maxAge   = (int) $this->option('hours');
        $dryRun   = (bool) $this->option('dry-run');
        $baseDir  = storage_path('app/fabric_exports');

        if (!is_dir($baseDir)) {
            $this->info('No existe el directorio de exports. Nada que limpiar.');
            return self::SUCCESS;
        }

        $cutoff   = now()->subHours($maxAge)->timestamp;
        $dirs     = File::directories($baseDir);
        $deleted  = 0;
        $freed    = 0;

        foreach ($dirs as $dir) {
            $mtime = filemtime($dir);

            if ($mtime >= $cutoff) {
                continue; // Aún es reciente, no tocar
            }

            $size = $this->dirSize($dir);
            $age  = round((time() - $mtime) / 3600, 1);

            if ($dryRun) {
                $this->line("  [dry-run] Eliminaría: " . basename($dir) . " ({$this->humanSize($size)}, {$age}h)");
            } else {
                File::deleteDirectory($dir);
                $this->line("  ✓ Eliminado: " . basename($dir) . " ({$this->humanSize($size)}, {$age}h)");
            }

            $deleted++;
            $freed += $size;
        }

        $this->newLine();
        $action = $dryRun ? 'Se eliminarían' : 'Eliminados';
        $this->info("{$action}: {$deleted} directorios, {$this->humanSize($freed)} liberados.");

        return self::SUCCESS;
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        foreach (File::allFiles($dir) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
