<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use App\Models\BiVista;
use App\Services\Fabric\ParquetScheduleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Deja `bi_parquet_config` solo con las vistas configuradas (habilitadas).
 *
 * Qué hace:
 *   1. Borra las filas con enabled = 0 (deshabilitadas, sin programar).
 *   2. Normaliza a minúsculas el schema de las que quedan (`CO` → `co`), que
 *      generaba entradas duplicadas en Graph-Fabric.
 *
 * Seguridad:
 *   - Sin --apply solo muestra el plan (dry-run). No toca nada.
 *   - Con --apply guarda ANTES un respaldo JSON completo de la tabla en
 *     storage/app/backups/, de donde se puede restaurar cualquier fila.
 *   - Todo el borrado va en una transacción.
 *
 * Uso:
 *   php artisan fabric:clean-parquet-config             # plan
 *   php artisan fabric:clean-parquet-config --apply     # ejecuta
 *   php artisan fabric:clean-parquet-config --apply --sync   # y deja a Graph igual
 */
class CleanParquetConfigCommand extends Command
{
    protected $signature = 'fabric:clean-parquet-config
        {--apply : Ejecuta la limpieza (sin esto solo muestra el plan)}
        {--sync : Al terminar, deja el scheduler de Graph-Fabric igual a la tabla}';

    protected $description = 'Deja bi_parquet_config solo con las vistas habilitadas (respalda antes de borrar)';

    public function handle(ParquetScheduleSyncService $sync): int
    {
        $all   = BiParquetConfig::all();
        $index = $sync->estadoIndex(true);

        // Motivo por el que una fila se borra (null = se conserva).
        // Mantenimiento se CONSERVA: es temporal y el sync simplemente no la envia.
        $motivo = function (BiParquetConfig $c) use ($sync, $index): ?string {
            if (! $c->enabled) {
                return 'deshabilitada';
            }
            $estado = $sync->estadoDe($c->schema_name, $c->view_name, $index);

            return match ($estado) {
                null                        => 'no existe en bi_vistas',
                BiVista::ESTADO_INACTIVO    => 'inactiva en la plataforma',
                default                     => null,
            };
        };

        $drop = $all->filter(fn (BiParquetConfig $c): bool => $motivo($c) !== null);
        $keep = $all->reject(fn (BiParquetConfig $c): bool => $motivo($c) !== null);
        $porMotivo = $drop->groupBy(fn (BiParquetConfig $c): string => (string) $motivo($c))->map->count();
        $enMantenimiento = $keep->filter(
            fn (BiParquetConfig $c): bool => $sync->estadoDe($c->schema_name, $c->view_name, $index) === BiVista::ESTADO_MANTENIMIENTO
        )->count();

        // Schemas en mayúsculas entre las que se conservan
        // Habilitadas que se van por no ser validas: se listan para que se vea que se borra
        $invalidas = $drop->filter(fn (BiParquetConfig $c): bool => $c->enabled);
        if ($invalidas->isNotEmpty()) {
            $this->newLine();
            $this->warn('Habilitadas que se retiran por no estar activas:');
            foreach ($invalidas as $c) {
                $this->line("  {$c->qualifiedName()}  ({$motivo($c)})");
            }
        }

        $upper = $keep->filter(fn (BiParquetConfig $c): bool => $c->schema_name !== strtolower($c->schema_name));

        // Duplicados al pasar a minúsculas: abortar antes de tocar nada
        $dupes = $keep->groupBy(fn (BiParquetConfig $c): string => strtolower("{$c->schema_name}.{$c->view_name}"))
            ->filter(fn ($g): bool => $g->count() > 1);

        $this->info('=== Limpieza de bi_parquet_config ===');
        $this->line($this->option('apply') ? '(APLICANDO)' : '(DRY-RUN: no se modifica nada)');
        $this->newLine();

        $this->table(
            ['Concepto', 'Filas'],
            [
                ['Total en la tabla', $all->count()],
                ['Se conservan (habilitadas y activas)', $keep->count()],
                ['  de ellas en mantenimiento (no se envian al cron)', $enMantenimiento],
                ['Se borran', $drop->count()],
                ['  - deshabilitadas', $porMotivo['deshabilitada'] ?? 0],
                ['  - habilitadas pero inactivas en la plataforma', $porMotivo['inactiva en la plataforma'] ?? 0],
                ['  - habilitadas pero NO existen en bi_vistas', $porMotivo['no existe en bi_vistas'] ?? 0],
                ['Con schema en mayusculas (se normalizan)', $upper->count()],
            ]
        );

        $this->line('Se conservan, por prioridad:');
        $this->table(
            ['Prioridad', 'Vistas'],
            $keep->groupBy('priority')->map(fn ($g, $p): array => [$p, $g->count()])->values()->all()
        );

        $demanda = $keep->sum(fn (BiParquetConfig $c): float => 1440 / max(1, (int) $c->refresh_interval_min));
        $this->line(sprintf('Generaciones/dia que pedira el scheduler con lo que queda: %.0f (~%.1f por minuto)', $demanda, $demanda / 1440));

        if ($upper->isNotEmpty()) {
            $this->newLine();
            $this->warn('Schema en mayusculas:');
            foreach ($upper as $c) {
                $this->line("  {$c->schema_name}.{$c->view_name} -> " . strtolower($c->schema_name) . ".{$c->view_name}");
            }
        }

        if ($dupes->isNotEmpty()) {
            $this->newLine();
            $this->error('Hay vistas duplicadas al normalizar a minusculas; resuelva esto a mano antes de continuar:');
            foreach ($dupes->keys() as $k) {
                $this->line("  {$k}");
            }

            return 1;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN: no se cambio nada. Ejecute con --apply para aplicar.');

            return 0;
        }

        // ── Respaldo antes de borrar ─────────────────────────────────────────
        $path = storage_path('app/backups/bi_parquet_config_' . now()->format('Ymd_His') . '.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $all->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $guardadas = count(json_decode((string) File::get($path), true) ?? []);
        if ($guardadas !== $all->count()) {
            $this->error("El respaldo no coincide ({$guardadas} de {$all->count()}). No se borra nada.");

            return 1;
        }
        $this->info("Respaldo guardado: {$path} ({$guardadas} filas)");

        // ── Limpieza en una transaccion ──────────────────────────────────────
        DB::transaction(function () use ($drop, $upper): void {
            foreach ($drop->pluck('id')->chunk(500) as $ids) {
                BiParquetConfig::whereIn('id', $ids->all())->delete();
            }
            foreach ($upper as $c) {
                $c->update(['schema_name' => strtolower($c->schema_name)]);
            }
        });

        $this->newLine();
        $this->info('Listo. Filas ahora en la tabla: ' . BiParquetConfig::count()
            . ' (todas habilitadas: ' . BiParquetConfig::enabled()->count() . ').');

        if ($this->option('sync')) {
            $this->newLine();
            $res = $sync->syncAll();
            $res['ok'] ? $this->info($res['message']) : $this->error($res['message']);

            return $res['ok'] ? 0 : 1;
        }

        $this->warn('Falta dejar a Graph-Fabric igual: php artisan fabric:sync-parquet-config');

        return 0;
    }
}
