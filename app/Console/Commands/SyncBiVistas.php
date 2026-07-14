<?php

namespace App\Console\Commands;

use App\Models\BiGrupo;
use App\Models\BiVista;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncBiVistas extends Command
{
    protected $signature = 'bi:sync-vistas
        {--schema= : Sincronizar solo un esquema (ej: ca, in, aa)}
        {--force : Forzar re-sincronización aunque ya existan}';

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
        $this->info("Esquemas a sincronizar: {$grupos->count()}");
        $this->newLine();

        $totalCreated  = 0;
        $totalExisting = 0;
        $totalErrors   = 0;

        $bar = $this->output->createProgressBar($grupos->count());
        $bar->start();

        foreach ($grupos as $grupo) {
            $schema = strtolower($grupo->codigo);

            $response = Http::timeout(130)
                ->connectTimeout(10)
                ->acceptJson()
                ->post("{$url}/api/catalog/views", [
                    'token'       => $token,
                    'groups'      => ['GG-BD-' . strtoupper($schema), 'GG-BD-ADMIN'],
                    'department'  => 'NAL',
                    'user_email'  => 'sistema@medilaser.com.co',
                    'user_name'   => 'Sistema Sync',
                    'schema_name' => $schema,
                ]);

            if ($response->failed()) {
                $totalErrors++;
                $this->newLine();
                $this->warn("  [{$schema}] Error HTTP {$response->status()}");
                $bar->advance();
                continue;
            }

            $data    = $response->json();
            $schemas = $data['schemas'] ?? [];
            
            $vistasRecibidas = [];

            foreach ($schemas as $schemaBlock) {
                $views = $schemaBlock['views'] ?? [];

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
                    
                    if (!$biVista->wasRecentlyCreated && $biVista->estado !== BiVista::ESTADO_ACTIVO) {
                        $biVista->update(['estado' => BiVista::ESTADO_ACTIVO]);
                    }

                    $vistasRecibidas[] = $biVista->id;

                    if ($biVista->wasRecentlyCreated) {
                        $totalCreated++;
                    } else {
                        $totalExisting++;
                    }
                }
            }

            if (!empty($vistasRecibidas)) {
                BiVista::where('id_bi_grupos', $grupo->id)
                    ->whereNotIn('id', $vistasRecibidas)
                    ->where('estado', '!=', BiVista::ESTADO_INACTIVO)
                    ->update(['estado' => BiVista::ESTADO_INACTIVO]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("═══════════════════════════════════");
        $this->info("  Vistas nuevas creadas: {$totalCreated}");
        $this->info("  Vistas ya existentes:  {$totalExisting}");
        $this->info("  Esquemas con error:    {$totalErrors}");
        $this->info("  Total en bi_vistas:    " . BiVista::count());
        $this->info("═══════════════════════════════════");

        return 0;
    }
}
