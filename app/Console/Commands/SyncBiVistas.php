<?php

namespace App\Console\Commands;

use App\Models\BiGrupo;
use App\Models\BiVista;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncBiVistas extends Command
{
    protected $signature = 'bi:sync-vistas
        {--schema= : Sincronizar solo un esquema (ej: ca, in, aa)}
        {--force : Forzar re-sincronización aunque ya existan}
        {--timeout=300 : Timeout en segundos para la llamada a Graph-Fabric}';

    protected $description = 'Sincroniza las vistas de Fabric (Graph-Fabric API) con la tabla bi_vistas';

    public function handle(): int
    {
        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        if ($token === '') {
            $this->error('TOKEN_ADMIN no está configurado en .env');
            return 1;
        }

        $schemaFilter = $this->option('schema');
        $timeout = (int) $this->option('timeout');

        // Obtener grupos (esquemas cortos: AA, CA, IN, etc.)
        $query = BiGrupo::whereRaw("LENGTH(codigo) <= 4")->orderBy('codigo');

        if ($schemaFilter) {
            $query->where('codigo', strtoupper($schemaFilter));
        }

        $grupos = $query->get();

        if ($grupos->isEmpty()) {
            $this->error($schemaFilter
                ? "No se encontró el grupo con código '{$schemaFilter}'"
                : 'No hay grupos en bi_grupos');
            return 1;
        }

        $this->info("Sincronizando vistas de Fabric → bi_vistas");
        $this->info("API: {$url}");
        $this->info("Timeout: {$timeout}s");
        $this->info("Esquemas en BD: {$grupos->count()}");
        $this->newLine();

        // ══════════════════════════════════════════════════════════════════
        // ESTRATEGIA: Una sola llamada a Graph-Fabric sin schema_name
        // Devuelve TODOS los esquemas con sus vistas (3.300+ vistas).
        // Esto evita 20 llamadas secuenciales y aprovecha el routing
        // multi-endpoint que consulta los 7 Lakehouses en paralelo.
        // ══════════════════════════════════════════════════════════════════

        $this->info("Consultando catálogo completo de Fabric...");

        $payload = [
            'token'      => $token,
            'groups'     => ['GG-BD-ADMIN'],
            'department' => 'MA-TIC',
            'user_email' => 'sistema@medilaser.com.co',
            'user_name'  => 'Sistema Sync',
        ];

        // Si se filtra un esquema específico, pasar schema_name para respuesta más rápida
        if ($schemaFilter) {
            $payload['schema_name'] = strtolower($schemaFilter);
        }

        $response = Http::timeout($timeout)
            ->connectTimeout(30)
            ->retry(2, 5000, function ($exception) {
                // Reintentar solo en timeout o error de conexión
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            })
            ->acceptJson()
            ->post("{$url}/api/catalog/views", $payload);

        if ($response->failed()) {
            $this->error("Error HTTP {$response->status()} al consultar Graph-Fabric");
            $this->error($response->body());
            Log::error('[BI-SYNC] Error al consultar Graph-Fabric', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            return 1;
        }

        $data = $response->json();
        $schemas = $data['schemas'] ?? [];

        if (empty($schemas)) {
            $this->warn("Graph-Fabric no devolvió esquemas. Verificar el servicio.");
            return 1;
        }

        $totalVistasFabric = collect($schemas)->sum(fn($s) => count($s['views'] ?? []));
        $this->info("Recibido: " . count($schemas) . " esquemas, {$totalVistasFabric} vistas totales");
        $this->newLine();

        // ══════════════════════════════════════════════════════════════════
        // Procesar localmente: mapear schemas de Fabric a bi_grupos
        // ══════════════════════════════════════════════════════════════════

        $totalCreated  = 0;
        $totalExisting = 0;
        $totalUpdated  = 0;
        $totalInactive = 0;
        $schemasProcessed = 0;

        $bar = $this->output->createProgressBar($grupos->count());
        $bar->start();

        // Indexar schemas de Fabric por código para O(1) lookup
        $fabricSchemaMap = [];
        foreach ($schemas as $schemaBlock) {
            $schemaCode = strtoupper($schemaBlock['schema'] ?? $schemaBlock['schema_name'] ?? '');
            if ($schemaCode !== '') {
                $fabricSchemaMap[$schemaCode] = $schemaBlock['views'] ?? [];
            }
        }

        foreach ($grupos as $grupo) {
            $schemaCode = strtoupper($grupo->codigo);
            $views = $fabricSchemaMap[$schemaCode] ?? [];

            $vistasRecibidas = [];

            foreach ($views as $view) {
                $viewName = $view['view_name'] ?? '';
                if ($viewName === '') {
                    continue;
                }

                $biVista = BiVista::firstOrCreate(
                    [
                        'id_bi_grupos' => $grupo->id,
                        'nombre'       => $viewName,
                    ],
                    [
                        'descripcion' => $view['qualified_name'] ?? null,
                        'estado'      => BiVista::ESTADO_ACTIVO,
                    ]
                );

                // Si existía pero estaba inactiva, reactivar
                if (!$biVista->wasRecentlyCreated && $biVista->estado !== BiVista::ESTADO_ACTIVO) {
                    $biVista->update(['estado' => BiVista::ESTADO_ACTIVO]);
                    $totalUpdated++;
                }

                $vistasRecibidas[] = $biVista->id;

                if ($biVista->wasRecentlyCreated) {
                    $totalCreated++;
                } else {
                    $totalExisting++;
                }
            }

            // Marcar como inactivas las vistas que ya no están en Fabric
            if (!empty($vistasRecibidas)) {
                $inactivated = BiVista::where('id_bi_grupos', $grupo->id)
                    ->whereNotIn('id', $vistasRecibidas)
                    ->where('estado', '!=', BiVista::ESTADO_INACTIVO)
                    ->update(['estado' => BiVista::ESTADO_INACTIVO]);
                $totalInactive += $inactivated;
            }

            if (!empty($views)) {
                $schemasProcessed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("═══════════════════════════════════════════════");
        $this->info("  Esquemas con vistas en Fabric: {$schemasProcessed}");
        $this->info("  Vistas nuevas creadas:         {$totalCreated}");
        $this->info("  Vistas ya existentes:          {$totalExisting}");
        $this->info("  Vistas reactivadas:            {$totalUpdated}");
        $this->info("  Vistas marcadas inactivas:     {$totalInactive}");
        $this->info("  Total en bi_vistas:            " . BiVista::count());
        $this->info("═══════════════════════════════════════════════");

        Log::info('[BI-SYNC] Sincronización completada', [
            'schemas_fabric' => count($schemas),
            'schemas_procesados' => $schemasProcessed,
            'nuevas' => $totalCreated,
            'existentes' => $totalExisting,
            'inactivas' => $totalInactive,
        ]);

        return 0;
    }
}
