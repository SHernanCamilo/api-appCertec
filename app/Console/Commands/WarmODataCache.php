<?php

namespace App\Console\Commands;

use App\Models\OdataAccessLog;
use App\Models\OdataLink;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pre-calienta el cache Redis de las vistas OData más consultadas.
 *
 * Ejecuta las mismas queries que haría Power Query, pero en background.
 * Cuando Excel refresque, la respuesta sale de cache → 0 espera en Fabric.
 *
 * Uso:
 *   php artisan odata:warm-cache              → Top 10 links más usados
 *   php artisan odata:warm-cache --all        → Todos los links activos
 *   php artisan odata:warm-cache --link=abc   → Solo un link específico
 *   php artisan odata:warm-cache --pages=5    → Precalentar 5 páginas (100K filas)
 *
 * Cron recomendado: cada 30 min (según el TTL de cache más común)
 *   0,30 * * * * php artisan odata:warm-cache
 */
class WarmODataCache extends Command
{
    protected $signature = 'odata:warm-cache
        {--all : Calentar todos los links activos}
        {--link= : Código de un link específico}
        {--top=10 : Cantidad de links top a calentar}
        {--pages=3 : Cantidad de páginas a precalentar por link}';

    protected $description = 'Pre-calienta cache Redis de vistas OData populares para eliminar esperas de Fabric';

    public function handle(GraphFabricGatewayService $gateway): int
    {
        $links = $this->resolveLinks();

        if ($links->isEmpty()) {
            $this->warn('No hay links OData activos para calentar.');
            return 0;
        }

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

        $this->info("═══ Resumen ═══");
        $this->info("  Links procesados:  {$links->count()}");
        $this->info("  Páginas cacheadas: {$totalCached}");
        $this->info("  Filas totales:     " . number_format($totalRows));
        $this->info("  Errores:           {$errors}");

        if ($errors > 0) {
            $this->warn("Revisar logs para detalles de errores.");
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
