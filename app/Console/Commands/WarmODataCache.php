<?php

namespace App\Console\Commands;

use App\Models\OdataAccessLog;
use App\Models\OdataLink;
use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\ODataSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mantiene calientes las vistas OData más consultadas.
 *
 * Con ODATA_USE_SNAPSHOT activo (modo por defecto) refresca los snapshots NDJSON:
 * valida el conteo contra Fabric y, si cambió, vuelve a bajar el dataset. Así
 * cuando Excel refresca ya encuentra datos nuevos en disco y no espera nada.
 *
 * Con el modo legacy precalienta el cache Redis por página.
 *
 * Uso:
 *   php artisan odata:warm-cache              → Top 10 links más usados
 *   php artisan odata:warm-cache --all        → Todos los links activos
 *   php artisan odata:warm-cache --link=abc   → Solo un link específico
 *   php artisan odata:warm-cache --force      → Rebuild sin validar conteo
 *   php artisan odata:warm-cache --pages=5    → (solo modo legacy) 5 páginas
 *
 * Cron: cada 30 min desde App\Console\Kernel.
 */
class WarmODataCache extends Command
{
    protected $signature = 'odata:warm-cache
        {--all : Calentar todos los links activos}
        {--link= : Código de un link específico}
        {--top=10 : Cantidad de links top a calentar}
        {--pages=3 : Cantidad de páginas a precalentar por link (modo legacy)}
        {--force : Reconstruir el snapshot sin validar el conteo primero}';

    protected $description = 'Mantiene frescos los snapshots/cache de las vistas OData más consultadas';

    public function handle(GraphFabricGatewayService $gateway, ODataSnapshotService $snapshots): int
    {
        $links = $this->resolveLinks();

        if ($links->isEmpty()) {
            $this->warn('No hay links OData activos para calentar.');
            return 0;
        }

        $useSnapshot = filter_var(env('ODATA_USE_SNAPSHOT', true), FILTER_VALIDATE_BOOLEAN);

        return $useSnapshot
            ? $this->warmSnapshots($snapshots, $links)
            : $this->warmLegacyCache($gateway, $links);
    }

    // =========================================================================
    // MODO SNAPSHOT (por defecto)
    // =========================================================================

    private function warmSnapshots(ODataSnapshotService $snapshots, $links): int
    {
        $force = (bool) $this->option('force');
        $this->info("Refrescando snapshots de {$links->count()} link(s)" . ($force ? ' (forzado)' : '') . '...');

        $rebuilt = 0;
        $unchanged = 0;
        $errors = 0;
        $totalRows = 0;

        foreach ($links as $link) {
            $t0 = microtime(true);

            try {
                $result = $snapshots->refresh($link->code, $this->contextFor($link), $force);

                $source = $result['source'] ?? '?';
                $rows   = (int) ($result['rows'] ?? 0);
                $totalRows += $rows;

                if ($source === 'unchanged') {
                    $unchanged++;
                } else {
                    $rebuilt++;
                }

                $elapsed = round(microtime(true) - $t0, 1);
                $this->line(sprintf(
                    '  %-12s %-40s %-10s %8s filas  %ss',
                    $link->code,
                    $link->view_name,
                    $source,
                    number_format($rows),
                    $elapsed
                ));
            } catch (\Throwable $e) {
                $errors++;
                $this->line("  {$link->code} {$link->view_name}  ERROR: {$e->getMessage()}");
                Log::warning('odata:warm-cache - refresh falló', [
                    'link'  => $link->code,
                    'view'  => $link->view_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('═══ Resumen ═══');
        $this->info("  Reconstruidos:  {$rebuilt}");
        $this->info("  Sin cambios:    {$unchanged}");
        $this->info('  Filas totales:  ' . number_format($totalRows));
        $this->info("  Errores:        {$errors}");

        return 0;
    }

    /**
     * Contexto del dataset: debe coincidir exactamente con el que arma
     * ODataController::fetchData, o el snapshot se guarda con otro fingerprint.
     */
    private function contextFor(OdataLink $link): array
    {
        return [
            'schema'   => $link->schema_name,
            'view'     => $link->view_name,
            'filters'  => $link->filters ?? [],
            'columns'  => $link->columns ?? [],
            'sort_col' => $link->sort_col ?? '',
            'sort_dir' => $link->sort_dir ?? 'asc',
            'max_rows' => $link->max_rows ?? 1000000,
        ];
    }

    // =========================================================================
    // MODO LEGACY (cache Redis por página)
    // =========================================================================

    private function warmLegacyCache(GraphFabricGatewayService $gateway, $links): int
    {
        $pages = max(1, min(20, (int) $this->option('pages')));
        $this->info("Calentando cache para {$links->count()} link(s), {$pages} página(s) cada uno...");

        $bar = $this->output->createProgressBar($links->count());
        $bar->start();

        $totalCached = 0;
        $totalRows = 0;
        $errors = 0;

        foreach ($links as $link) {
            $result = $this->warmLink($gateway, $link, $pages);
            if ($result['success']) {
                $totalCached += $result['pages_cached'];
                $totalRows += $result['rows'];
            } else {
                $errors++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('═══ Resumen ═══');
        $this->info("  Links procesados:  {$links->count()}");
        $this->info("  Páginas cacheadas: {$totalCached}");
        $this->info('  Filas totales:     ' . number_format($totalRows));
        $this->info("  Errores:           {$errors}");

        if ($errors > 0) {
            $this->warn('Revisar logs para detalles de errores.');
        }

        return 0;
    }

    private function resolveLinks()
    {
        if ($code = $this->option('link')) {
            return OdataLink::where('code', $code)->where('active', true)->get();
        }

        if ($this->option('all')) {
            return OdataLink::where('active', true)->get();
        }

        // Top N por accesos recientes (últimos 7 días)
        $top = (int) $this->option('top');

        $topCodes = OdataAccessLog::query()
            ->select('odata_link_id')
            ->where('accessed_at', '>=', now()->subDays(7))
            ->groupBy('odata_link_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($top)
            ->pluck('odata_link_id');

        if ($topCodes->isEmpty()) {
            // Fallback: links activos ordenados por access_count
            return OdataLink::where('active', true)
                ->orderByDesc('access_count')
                ->limit($top)
                ->get();
        }

        return OdataLink::whereIn('id', $topCodes)->where('active', true)->get();
    }

    private function warmLink(GraphFabricGatewayService $gateway, OdataLink $link, int $maxPages): array
    {
        $pageSize = (int) ($link->page_size ?? 20000);
        $cacheTtl = max(30, (int) ($link->cache_ttl ?? 120));
        $columns = $link->columns ?? [];
        $filters = $link->filters ?? [];
        $sortCol = $link->sort_col ?? '';
        $sortDir = $link->sort_dir ?? 'asc';

        $pagesCached = 0;
        $totalRows = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $skip = $page * $pageSize;

            $cacheKey = 'odata_qry:' . md5("{$link->code}:{$skip}:{$pageSize}:" . json_encode($filters) . ':' . json_encode($columns));

            // Saltar si ya está cacheado y vigente
            if (Cache::has($cacheKey)) {
                $pagesCached++;
                $cached = Cache::get($cacheKey);
                $totalRows += count($cached['data'] ?? []);
                continue;
            }

            try {
                $result = $gateway->queryAsSystem(
                    $link->schema_name,
                    $link->view_name,
                    [
                        'columns'  => $columns,
                        'filters'  => $filters,
                        'limit'    => $pageSize,
                        'offset'   => $skip,
                        'sort_col' => $sortCol,
                        'sort_dir' => $sortDir,
                    ]
                );

                if (!$result['success']) {
                    Log::warning('odata:warm-cache - Query falló', [
                        'link' => $link->code,
                        'page' => $page,
                        'error' => $result['message'] ?? 'unknown',
                    ]);
                    return ['success' => false, 'pages_cached' => $pagesCached, 'rows' => $totalRows];
                }

                Cache::put($cacheKey, $result, $cacheTtl);
                $pagesCached++;

                $items = $result['data'] ?? [];
                $totalRows += count($items);

                // Si no hay más páginas, parar
                $hasNext = $result['meta']['has_next'] ?? (count($items) === $pageSize);
                if (!$hasNext || empty($items)) {
                    break;
                }
            } catch (\Throwable $e) {
                Log::error('odata:warm-cache - Exception', [
                    'link' => $link->code,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                return ['success' => false, 'pages_cached' => $pagesCached, 'rows' => $totalRows];
            }

            // Pequeña pausa entre páginas para no saturar Graph-Fabric
            usleep(500_000); // 0.5s
        }

        return ['success' => true, 'pages_cached' => $pagesCached, 'rows' => $totalRows];
    }
}
