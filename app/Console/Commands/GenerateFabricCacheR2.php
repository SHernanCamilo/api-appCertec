<?php

namespace App\Console\Commands;

use App\Models\OdataAccessLog;
use App\Services\Fabric\R2CacheService;
use Illuminate\Console\Command;

/**
 * Genera cache CSV.gz en Cloudflare R2 para las vistas más consultadas.
 *
 * Uso:
 *   php artisan fabric:cache-r2              → Top 20 vistas más consultadas
 *   php artisan fabric:cache-r2 --schema=pt  → Solo vistas de un esquema
 *   php artisan fabric:cache-r2 --cleanup    → Solo limpiar archivos viejos
 *   php artisan fabric:cache-r2 --status     → Ver uso de R2
 *
 * Cron: cada hora
 *   0 * * * * php artisan fabric:cache-r2
 */
class GenerateFabricCacheR2 extends Command
{
    protected $signature = 'fabric:cache-r2
        {--schema= : Solo generar para un esquema (ej: pt, ca)}
        {--view= : Solo generar para una vista específica}
        {--cleanup : Solo limpiar archivos viejos}
        {--status : Mostrar estado de uso de R2}
        {--top=20 : Cantidad de vistas top a cachear}';

    protected $description = 'Genera cache CSV.gz en Cloudflare R2 para las vistas más consultadas';

    public function handle(): int
    {
        $r2 = new R2CacheService();

        if ($this->option('status')) {
            $usage = $r2->getUsage();
            $this->info("═══ Estado R2 ═══");
            $this->info("  Archivos: {$usage['files']}");
            $this->info("  Tamaño:   {$usage['size_human']}");
            $this->info("  Uso:      {$usage['usage_percent']}% de {$usage['max_gb']}GB");
            return 0;
        }

        if ($this->option('cleanup')) {
            $result = $r2->cleanup();
            $this->info("Limpieza: {$result['deleted']} archivos eliminados, {$result['freed']} liberados");
            return 0;
        }

        // Vista específica
        if ($this->option('view')) {
            $schema = $this->option('schema') ?? 'dc';
            $view = $this->option('view');
            $this->info("Generando: {$schema}.{$view}");
            $result = $r2->generateAndUpload($schema, $view);
            if ($result) {
                $this->info("  ✅ {$result['rows']} filas, {$result['size_human']}");
            } else {
                $this->error("  ❌ Error o sin datos");
            }
            return 0;
        }

        // Top N vistas más consultadas (desde odata_access_logs)
        $top = (int) $this->option('top');
        $schema = $this->option('schema');

        $query = OdataAccessLog::query()
            ->select('schema_name', 'view_name')
            ->selectRaw('COUNT(*) as accesos')
            ->selectRaw('MAX(accessed_at) as ultimo_acceso')
            ->groupBy('schema_name', 'view_name')
            ->orderByDesc('accesos')
            ->limit($top);

        if ($schema) {
            $query->where('schema_name', $schema);
        }

        $vistas = $query->get();

        if ($vistas->isEmpty()) {
            $this->warn("No hay registros de acceso. Generando para vistas activas...");
            // Fallback: usar bi_vistas activas
            $vistas = \App\Models\BiVista::activas()
                ->with('grupo:id,codigo')
                ->limit($top)
                ->get()
                ->map(fn($v) => (object)[
                    'schema_name' => strtolower($v->grupo?->codigo ?? ''),
                    'view_name' => $v->nombre,
                    'accesos' => 0,
                ])
                ->filter(fn($v) => $v->schema_name !== '');
        }

        $this->info("Generando cache para {$vistas->count()} vistas...");
        $bar = $this->output->createProgressBar($vistas->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($vistas as $vista) {
            $result = $r2->generateAndUpload($vista->schema_name, $vista->view_name);
            if ($result) {
                $success++;
            } else {
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Limpiar archivos viejos
        $cleanup = $r2->cleanup();

        // Mostrar resumen
        $usage = $r2->getUsage();
        $this->info("═══ Resumen ═══");
        $this->info("  Generadas: {$success} | Fallidas: {$failed}");
        $this->info("  Limpiadas: {$cleanup['deleted']} archivos ({$cleanup['freed']})");
        $this->info("  R2 total:  {$usage['size_human']} ({$usage['usage_percent']}% de {$usage['max_gb']}GB)");

        // Si supera 80% del límite, advertir
        if ($usage['usage_percent'] > 80) {
            $this->warn("⚠️  R2 supera 80% — considerar reducir vistas cacheadas");
        }

        return 0;
    }
}
