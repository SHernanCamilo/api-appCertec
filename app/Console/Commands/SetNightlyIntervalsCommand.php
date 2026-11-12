<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use App\Services\Fabric\ParquetScheduleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Pasa las vistas al esquema "una vez al dia" (barrido de las 04:00).
 *
 * Pone refresh_interval_min = 1440 a todas las configuradas EXCEPTO las de las
 * prioridades que deben seguir refrescandose durante el dia (por defecto solo
 * `realtime`: censos, triage, urgencias). Asi el scheduler del dia no vuelve a
 * generar lo que el barrido de la madrugada ya dejo fresco.
 *
 * Seguridad: sin --apply solo muestra el plan; con --apply guarda ANTES un
 * respaldo JSON de las filas que cambian y todo va en una transaccion.
 *
 * Uso:
 *   php artisan fabric:set-nightly-intervals
 *   php artisan fabric:set-nightly-intervals --keep=realtime,high --apply --sync
 */
class SetNightlyIntervalsCommand extends Command
{
    protected $signature = 'fabric:set-nightly-intervals
        {--keep=realtime : Prioridades que conservan su intervalo durante el dia (separadas por coma)}
        {--all : TODAS las vistas a intervalo diario (ignora --keep)}
        {--apply : Aplica los cambios (sin esto solo muestra el plan)}
        {--sync : Al terminar, deja el scheduler de Graph-Fabric igual a la tabla}';

    protected $description = 'Pone las vistas en intervalo diario (1440 min) salvo las prioridades que siguen durante el dia';

    private const DAILY = 1440;

    public function handle(ParquetScheduleSyncService $sync): int
    {
        $keep = $this->option('all') ? [] : collect(explode(',', (string) $this->option('keep')))
            ->map(fn (string $p): string => strtolower(trim($p)))->filter()->values()->all();

        $enabled = BiParquetConfig::enabled()->get();
        $cambian = $enabled->filter(fn (BiParquetConfig $c): bool => ! in_array(strtolower($c->priority), $keep, true)
            && (int) $c->refresh_interval_min !== self::DAILY);

        $gen = fn ($rows, bool $despues): float => $rows->sum(function (BiParquetConfig $c) use ($keep, $despues): float {
            $iv = $despues && ! in_array(strtolower($c->priority), $keep, true) ? self::DAILY : (int) $c->refresh_interval_min;

            return 1440 / max(1, $iv);
        });

        $this->info('=== Intervalos para el barrido de las 04:00 ===');
        $this->line($this->option('apply') ? '(APLICANDO)' : '(DRY-RUN: no se modifica nada)');
        $this->line('Siguen refrescandose durante el dia: ' . ($keep ? implode(', ', $keep) : '(ninguna)'));
        $this->newLine();

        $this->table(
            ['Prioridad', 'Vistas', 'Cambian a 1440 min', 'Generaciones/dia hoy', 'Generaciones/dia despues'],
            $enabled->groupBy(fn (BiParquetConfig $c): string => $c->priority)->map(function ($g, $p) use ($cambian, $gen): array {
                return [$p, $g->count(), $cambian->whereIn('id', $g->pluck('id'))->count(), round($gen($g, false)), round($gen($g, true))];
            })->values()->all()
        );
        $this->line(sprintf(
            'Total: %d habilitadas | %d cambian | generaciones/dia: %.0f -> %.0f (mas las %d del barrido de la madrugada)',
            $enabled->count(), $cambian->count(), $gen($enabled, false), $gen($enabled, true), $enabled->count()
        ));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('DRY-RUN: no se cambio nada. Ejecute con --apply para aplicar.');

            return 0;
        }

        if ($cambian->isEmpty()) {
            $this->info('Nada que cambiar.');

            return 0;
        }

        $path = storage_path('app/backups/bi_parquet_config_antes_nocturno_' . now()->format('Ymd_His') . '.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $cambian->values()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (count(json_decode((string) File::get($path), true) ?? []) !== $cambian->count()) {
            $this->error('El respaldo no coincide. No se cambia nada.');

            return 1;
        }
        $this->info("Respaldo de las {$cambian->count()} filas que cambian: {$path}");

        DB::transaction(function () use ($cambian): void {
            BiParquetConfig::whereIn('id', $cambian->pluck('id'))->update(['refresh_interval_min' => self::DAILY]);
        });
        $this->info('Listo: ' . $cambian->count() . ' vistas en intervalo diario.');

        if ($this->option('sync')) {
            $res = $sync->syncAll();
            $res['ok'] ? $this->info($res['message']) : $this->error($res['message']);

            return $res['ok'] ? 0 : 1;
        }

        $this->warn('Falta enviarlo a Graph-Fabric: php artisan fabric:sync-parquet-config');

        return 0;
    }
}
