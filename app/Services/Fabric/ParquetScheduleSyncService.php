<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use App\Models\BiParquetConfig;
use App\Models\BiVista;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Deja el scheduler de Graph-Fabric IGUAL a lo que debe ejecutarse.
 *
 * Una vista se programa SOLO si cumple las dos condiciones:
 *   1. esta habilitada en `bi_parquet_config`, y
 *   2. esta ACTIVA en la plataforma (`bi_vistas.estado = 'activo'`).
 *
 * Se envia en UNA llamada la lista completa y Graph-Fabric desactiva todo lo que
 * no venga en ella (`deactivate_unlisted`). Reemplaza el envio vista por vista y
 * los DELETE individuales: estos ultimos se mandaban con el body equivocado
 * (Graph los exige en la query string) y respondian 422, por eso las vistas
 * inactivas seguian ejecutandose en Graph.
 */
final class ParquetScheduleSyncService
{
    /** Prioridades de Laravel → las tres que entiende Graph-Fabric. */
    private const PRIORITY_MAP = [
        'realtime'  => 'realtime',
        'high'      => 'operativo',
        'medium'    => 'operativo',
        'low'       => 'analitico',
        'manual'    => 'analitico',
        'operativo' => 'operativo',
        'analitico' => 'analitico',
    ];

    public function __construct(
        private readonly GraphFabricService $graph,
        private readonly BiVistasSyncService $vistas,
    ) {}

    /**
     * Estado de una vista en la plataforma: activo | mantenimiento | inactivo,
     * o null si NO esta registrada en bi_vistas (nombre mal escrito, vista que no
     * existe, etc.).
     *
     * @param  array<string,string>|null  $index  indice precargado (evita recargarlo por vista)
     */
    public function estadoDe(string $schema, string $view, ?array $index = null): ?string
    {
        $index ??= $this->vistas->getEstadoIndex();

        return $index[strtolower(trim($schema)) . '.' . strtolower(trim($view))] ?? null;
    }

    /**
     * Indice "schema.vista" (minusculas) => estado en bi_vistas.
     *
     * @return array<string,string>
     */
    public function estadoIndex(bool $fresh = false): array
    {
        return $this->vistas->getEstadoIndex($fresh);
    }

    /**
     * Configuraciones que SI deben ejecutarse: habilitadas y activas.
     *
     * @return Collection<int, BiParquetConfig>
     */
    public function eligibleConfigs(): Collection
    {
        $index = $this->vistas->getEstadoIndex(true);

        return BiParquetConfig::enabled()->get()->filter(
            fn (BiParquetConfig $c): bool => $this->estadoDe($c->schema_name, $c->view_name, $index) === BiVista::ESTADO_ACTIVO
        )->values();
    }

    /**
     * @return array{ok:bool, sent:int, excluded:int, activated:int, deactivated:int, errors:int, message:string}
     */
    public function syncAll(): array
    {
        $enabled  = BiParquetConfig::enabled()->count();
        $configs  = $this->eligibleConfigs();
        $excluded = $enabled - $configs->count();

        // Proteccion: con lista vacia + deactivate_unlisted Graph apagaria TODO.
        if ($configs->isEmpty()) {
            return $this->result(false, 0, $excluded, 0, 0, 0,
                'No hay vistas habilitadas y activas; no se envia nada para no vaciar el schedule de Graph-Fabric.');
        }

        $views = $configs->map(fn (BiParquetConfig $c): array => [
            'schema_name'          => strtolower($c->schema_name),
            'view'                 => $c->view_name,
            'refresh_interval_min' => (int) $c->refresh_interval_min,
            'priority'             => self::PRIORITY_MAP[$c->priority] ?? 'operativo',
            'group_name'           => $c->group_name,
        ])->all();

        $res = $this->graph->scheduleSync($views, true);

        if (!$res['ok']) {
            Log::warning('[ParquetScheduleSync] Graph rechazo el sync masivo', ['message' => $res['message'] ?? null]);

            return $this->result(false, count($views), $excluded, 0, 0, 0, $res['message'] ?? 'Graph-Fabric no respondio.');
        }

        $d           = $res['data'];
        $activated   = (int) ($d['activated_or_updated'] ?? 0);
        $deactivated = (int) ($d['deactivated'] ?? 0);
        $errors      = (int) ($d['errors'] ?? 0);

        BiParquetConfig::whereIn('id', $configs->pluck('id'))->update(['last_synced_at' => now()]);

        Log::info('[ParquetScheduleSync] Sync masivo aplicado', [
            'enviadas' => count($views), 'excluidas_no_activas' => $excluded,
            'activadas' => $activated, 'desactivadas' => $deactivated, 'errores' => $errors,
        ]);

        $msg = "Graph-Fabric ahora programa {$activated} vistas; desactivo {$deactivated} que no estaban configuradas.";
        if ($excluded > 0) {
            $msg .= " {$excluded} habilitadas no se enviaron por no estar activas en la plataforma.";
        }

        return $this->result($errors === 0, count($views), $excluded, $activated, $deactivated, $errors, $msg);
    }

    /** @return array{ok:bool, sent:int, excluded:int, activated:int, deactivated:int, errors:int, message:string} */
    private function result(bool $ok, int $sent, int $excluded, int $activated, int $deactivated, int $errors, string $message): array
    {
        return compact('ok', 'sent', 'excluded', 'activated', 'deactivated', 'errors', 'message');
    }
}
