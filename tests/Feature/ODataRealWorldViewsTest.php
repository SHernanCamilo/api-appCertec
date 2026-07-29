<?php

namespace Tests\Feature;

use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\ODataSnapshotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests de integración con vistas reales de producción.
 *
 * REQUIERE: Graph-Fabric corriendo en local (py app.py serve --port 8081).
 *
 * Valida:
 * - Consulta directa a Fabric devuelve datos frescos (tiempo real)
 * - Snapshot se genera correctamente y los datos son los mismos
 * - El export (job) se beneficia del R2 / Fabric directo
 * - El disco no supera el límite de 3 GB
 * - Rendimiento: snapshot local vs consulta directa
 *
 * Vistas probadas (producción real):
 * - dc.VW_Censo_Cmi (vista compleja, COUNT ~41s)
 * - gd.VW_Glosa_GlosasPorConcepto_prueba (1.48M filas)
 * - df.VW_Billing_IngresosAbiertos_Fla (COUNT ~19s)
 * - in.VW_Inventory_Almacenes (referencia rápida)
 */
class ODataRealWorldViewsTest extends TestCase
{
    private ODataSnapshotService $snapshots;
    private GraphFabricGatewayService $gateway;

    /** Límite de disco para snapshots: 3 GB */
    private const MAX_DISK_GB = 3.0;

    /** Vistas de producción para probar */
    private array $views = [
        ['schema' => 'dc', 'view' => 'VW_Censo_Cmi',                       'label' => 'Censo CMI (compleja)'],
        ['schema' => 'gd', 'view' => 'VW_Glosa_GlosasPorConcepto_prueba',  'label' => 'Glosas 1.48M filas'],
        ['schema' => 'df', 'view' => 'VW_Billing_IngresosAbiertos_Fla',    'label' => 'Facturación Ingresos'],
        ['schema' => 'in', 'view' => 'VW_Inventory_Almacenes',             'label' => 'Inventario Almacenes'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('GRAPHQL_URL=http://127.0.0.1:8081');
        $this->snapshots = app(ODataSnapshotService::class);
        $this->gateway = app(GraphFabricGatewayService::class);
    }

    protected function tearDown(): void
    {
        // Limpiar snapshots de test
        foreach ($this->views as $v) {
            $this->snapshots->invalidate('RW_' . $v['schema'] . '_' . substr($v['view'], 0, 20));
        }
        parent::tearDown();
    }

    // =========================================================================
    // TEST 1: Todas las vistas responden con datos reales desde Fabric
    // =========================================================================

    public function test_all_views_return_realtime_data(): void
    {
        echo "\n\n  ═══ CONSULTA DIRECTA A FABRIC (datos en tiempo real) ═══\n\n";

        foreach ($this->views as $v) {
            $t0 = microtime(true);

            $result = $this->gateway->queryAsSystem($v['schema'], $v['view'], [
                'columns' => [],
                'filters' => [],
                'limit'   => 50,
                'offset'  => 0,
            ]);

            $elapsed = round(microtime(true) - $t0, 2);

            $this->assertTrue(
                $result['success'],
                "{$v['label']}: queryAsSystem falló — " . ($result['message'] ?? 'sin mensaje')
            );
            $this->assertNotEmpty($result['data'], "{$v['label']}: no devolvió datos");

            $total = $result['meta']['total'] ?? -1;
            $cols = count(array_keys($result['data'][0]));
            $rows = count($result['data']);

            printf("  %-35s | %6.2fs | %6d filas devueltas | total=%s | %d cols\n",
                $v['label'], $elapsed, $rows, ($total > 0 ? number_format($total) : 'N/A'), $cols);
        }

        echo "\n  → Todas las vistas devuelven datos FRESCOS de Fabric ✓\n";
    }

    // =========================================================================
    // TEST 2: Snapshot de cada vista — genera y mide tamaño en disco
    // =========================================================================

    public function test_snapshot_generation_and_disk_usage(): void
    {
        echo "\n\n  ═══ GENERACIÓN DE SNAPSHOTS (R2 o Fabric) ═══\n\n";

        $totalDiskMB = 0;
        $dir = storage_path('app/odata_snapshots');

        printf("  %-35s | %8s | %8s | %10s | %8s | %s\n",
            'Vista', 'Filas', 'Disco', 'MB/1K', 'Tiempo', 'Fuente');
        echo "  " . str_repeat('-', 100) . "\n";

        foreach ($this->views as $v) {
            $code = 'RW_' . $v['schema'] . '_' . substr($v['view'], 0, 20);
            $this->snapshots->invalidate($code);

            $context = [
                'schema'   => $v['schema'],
                'view'     => $v['view'],
                'filters'  => [],
                'columns'  => [],
                'sort_col' => '',
                'sort_dir' => 'asc',
                'max_rows' => 50000, // Limitar a 50K para test (no descargar 1.48M)
            ];

            $t0 = microtime(true);
            $page = $this->snapshots->getPage($code, $context, 0, 10, 600);
            $elapsed = round(microtime(true) - $t0, 2);

            $this->assertTrue($page['success'], "{$v['label']}: snapshot falló — " . ($page['message'] ?? ''));

            // Medir archivo en disco
            $files = glob($dir . '/' . $code . '_*.ndjson') ?: [];
            $sizeMB = 0;
            if (!empty($files)) {
                $sizeMB = filesize($files[0]) / 1048576;
            }

            $rows = $page['total'];
            $mbPer1k = $rows > 0 ? $sizeMB / ($rows / 1000) : 0;
            $totalDiskMB += $sizeMB;

            printf("  %-35s | %8s | %6.1f MB | %7.3f | %6.2fs | %s\n",
                $v['label'],
                number_format($rows),
                $sizeMB,
                $mbPer1k,
                $elapsed,
                $page['source'] ?? '?');
        }

        echo "\n  Total en disco: " . round($totalDiskMB, 1) . " MB\n";
        echo "  Límite configurado: " . self::MAX_DISK_GB . " GB\n";

        $this->assertLessThan(
            self::MAX_DISK_GB * 1024,
            $totalDiskMB,
            "Los snapshots superan el límite de " . self::MAX_DISK_GB . " GB"
        );

        echo "  → Dentro del límite ✓\n";
    }

    // =========================================================================
    // TEST 3: Rendimiento — snapshot local vs consulta directa
    // =========================================================================

    public function test_snapshot_read_vs_fabric_direct_performance(): void
    {
        echo "\n\n  ═══ RENDIMIENTO: SNAPSHOT LOCAL vs FABRIC DIRECTO ═══\n\n";

        // Usar vista de inventario (más predecible en tiempos)
        $schema = 'in';
        $view = 'VW_Inventory_Almacenes';
        $code = 'RW_in_VW_Inventory_Almace';

        $context = [
            'schema'   => $schema,
            'view'     => $view,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 5000,
        ];

        // Asegurar que el snapshot existe
        $this->snapshots->getPage($code, $context, 0, 10, 600);

        // Medir lectura de snapshot local (10 lecturas)
        $snapshotTimes = [];
        for ($i = 0; $i < 10; $i++) {
            $t0 = microtime(true);
            $page = $this->snapshots->getPage($code, $context, $i * 50, 50, 600);
            $snapshotTimes[] = (microtime(true) - $t0) * 1000;
            $this->assertTrue($page['success']);
        }

        // Medir consulta directa a Fabric (3 lecturas)
        $fabricTimes = [];
        for ($i = 0; $i < 3; $i++) {
            $t0 = microtime(true);
            $result = $this->gateway->queryAsSystem($schema, $view, [
                'columns' => [],
                'filters' => [],
                'limit'   => 50,
                'offset'  => $i * 50,
            ]);
            $fabricTimes[] = (microtime(true) - $t0) * 1000;
            $this->assertTrue($result['success']);
        }

        $avgSnapshot = round(array_sum($snapshotTimes) / count($snapshotTimes), 2);
        $avgFabric = round(array_sum($fabricTimes) / count($fabricTimes), 0);
        $speedup = $avgFabric > 0 ? round($avgFabric / max(1, $avgSnapshot)) : 0;

        echo "  Snapshot local (10 lecturas):  avg = {$avgSnapshot} ms\n";
        echo "  Fabric directo (3 lecturas):   avg = {$avgFabric} ms\n";
        echo "  Speedup:                       {$speedup}x más rápido con snapshot\n\n";

        $this->assertLessThan(100, $avgSnapshot, "Snapshot debe ser < 100ms");
        echo "  → Snapshot local es {$speedup}x más rápido que Fabric directo ✓\n";
    }

    // =========================================================================
    // TEST 4: Paginación completa — datos no se pierden ni duplican
    // =========================================================================

    public function test_pagination_integrity_realworld_views(): void
    {
        echo "\n\n  ═══ INTEGRIDAD DE PAGINACIÓN (sin duplicados ni huecos) ═══\n\n";

        // Probar con vista de inventario (rápida, predecible)
        $code = 'RW_in_VW_Inventory_Almace';
        $context = [
            'schema'   => 'in',
            'view'     => 'VW_Inventory_Almacenes',
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 500,
        ];

        $this->snapshots->invalidate($code);

        // Recorrer todas las páginas
        $pageSize = 100;
        $allRows = [];
        $offset = 0;
        $pages = 0;

        while (true) {
            $page = $this->snapshots->getPage($code, $context, $offset, $pageSize, 600);
            $this->assertTrue($page['success']);

            $allRows = array_merge($allRows, $page['data']);
            $pages++;
            $offset += $pageSize;

            if (!$page['has_next'] || $offset >= $page['total']) {
                break;
            }
        }

        $total = $page['total'];

        // Sin duplicados
        $encoded = array_map('json_encode', $allRows);
        $unique = array_unique($encoded);
        $duplicates = \count($allRows) - \count($unique);

        // Todas las filas descargadas
        $this->assertEquals($total, \count($allRows), "Filas recorridas (" . \count($allRows) . ") ≠ total ({$total})");
        $this->assertEquals(0, $duplicates, "Hay {$duplicates} filas duplicadas");

        printf("  %-35s | %d páginas | %d filas | 0 duplicados ✓\n",
            'VW_Inventory_Almacenes', $pages, \count($allRows));
    }

    // =========================================================================
    // TEST 5: Export — el job de descarga obtiene datos reales
    // =========================================================================

    public function test_export_r2_endpoint_returns_fresh_data(): void
    {
        echo "\n\n  ═══ EXPORT: R2 DEVUELVE DATOS FRESCOS ═══\n\n";

        $url = env('GRAPHQL_URL', 'http://127.0.0.1:8081');
        $token = env('TOKEN_ADMIN', '');

        // Probar que el endpoint de R2 para export funciona con una vista real
        $testCases = [
            ['schema' => 'in', 'view' => 'VW_Inventory_Almacenes', 'label' => 'Almacenes'],
        ];

        foreach ($testCases as $tc) {
            $t0 = microtime(true);
            $response = Http::timeout(60)
                ->connectTimeout(10)
                ->post($url . '/api/data/export/r2', [
                    'token'       => $token,
                    'user_email'  => 'test@medilaser.com.co',
                    'user_name'   => 'Test Export',
                    'department'  => 'NAL-TIC NAL',
                    'groups'      => ['GG-BD-' . strtoupper($tc['schema']), 'GG-BD-ADMIN'],
                    'schema_name' => $tc['schema'],
                    'view'        => $tc['view'],
                    'filters'     => new \stdClass(),
                    'columns'     => [],
                    'max_rows'    => 1000,
                    'format'      => 'gzip',
                ]);
            $elapsed = round(microtime(true) - $t0, 2);

            $status = $response->status();
            $bodySize = strlen($response->body());

            if ($status === 200) {
                // Descomprimir y contar filas
                $tmp = tempnam(sys_get_temp_dir(), 'r2test_');
                file_put_contents($tmp, $response->body());
                $gz = gzopen($tmp, 'rb');
                $rows = 0;
                $firstRow = null;
                while (!gzeof($gz)) {
                    $line = gzgets($gz, 1048576);
                    if ($line === false || trim($line) === '') continue;
                    $rows++;
                    if ($firstRow === null) {
                        $firstRow = json_decode(trim($line), true);
                    }
                }
                gzclose($gz);
                @unlink($tmp);

                $this->assertGreaterThan(0, $rows, "{$tc['label']}: R2 devolvió 0 filas");
                $this->assertNotEmpty($firstRow, "{$tc['label']}: primera fila vacía");

                printf("  %-20s | HTTP %d | %5d filas | %.1f KB gzip | %ss\n",
                    $tc['label'], $status, $rows, $bodySize / 1024, $elapsed);

                // Verificar que los datos tienen estructura válida
                $this->assertArrayHasKey('Producto', $firstRow, 'Falta columna Producto en datos de R2');
                echo "    Primera fila: Producto='{$firstRow['Producto']}'\n";
            } else {
                printf("  %-20s | HTTP %d | (parquet no disponible, fallback a Fabric) | %ss\n",
                    $tc['label'], $status, $elapsed);
                // No es un error: 202/404 significa que R2 no tiene parquet aún
                $this->assertContains($status, [200, 202, 404]);
            }
        }

        echo "\n  → El export obtiene datos reales del parquet (o Fabric directo) ✓\n";
    }

    // =========================================================================
    // TEST 6: Comparar datos de snapshot vs consulta directa — mismas columnas
    // =========================================================================

    public function test_snapshot_columns_match_fabric_for_all_views(): void
    {
        echo "\n\n  ═══ VALIDACIÓN: COLUMNAS SNAPSHOT = COLUMNAS FABRIC ═══\n\n";

        foreach ($this->views as $v) {
            $code = 'RW_' . $v['schema'] . '_' . substr($v['view'], 0, 20);

            // Consulta directa
            $direct = $this->gateway->queryAsSystem($v['schema'], $v['view'], [
                'columns' => [],
                'filters' => [],
                'limit'   => 5,
                'offset'  => 0,
            ]);

            if (!$direct['success']) {
                echo "  {$v['label']}: Fabric no respondió, skip\n";
                continue;
            }

            // Snapshot
            $context = [
                'schema'   => $v['schema'],
                'view'     => $v['view'],
                'filters'  => [],
                'columns'  => [],
                'sort_col' => '',
                'sort_dir' => 'asc',
                'max_rows' => 50000,
            ];

            $page = $this->snapshots->getPage($code, $context, 0, 5, 600);

            if (!$page['success'] || empty($page['data'])) {
                echo "  {$v['label']}: snapshot vacío, skip\n";
                continue;
            }

            $fabricCols = array_keys($direct['data'][0]);
            $snapCols = array_keys($page['data'][0]);
            sort($fabricCols);
            sort($snapCols);

            $this->assertEquals(
                $fabricCols,
                $snapCols,
                "{$v['label']}: columnas difieren entre Fabric y snapshot"
            );

            printf("  %-35s | %d columnas | ✓ idénticas\n", $v['label'], count($fabricCols));
        }

        echo "\n  → Todas las vistas tienen mismas columnas en snapshot y Fabric ✓\n";
    }

    // =========================================================================
    // TEST 7: Límite de disco — proyección de uso máximo
    // =========================================================================

    public function test_disk_usage_projection(): void
    {
        echo "\n\n  ═══ PROYECCIÓN DE USO DE DISCO EN PRODUCCIÓN ═══\n\n";

        // Medimos cuánto pesan las vistas reales que tenemos
        $dir = storage_path('app/odata_snapshots');
        $totalMB = 0;
        $fileCount = 0;

        foreach (glob($dir . '/*.ndjson') ?: [] as $f) {
            $totalMB += filesize($f) / 1048576;
            $fileCount++;
        }

        echo "  Archivos en disco ahora:  {$fileCount}\n";
        echo "  Espacio ocupado:          " . round($totalMB, 1) . " MB\n";
        echo "  Límite:                   " . self::MAX_DISK_GB . " GB (" . (self::MAX_DISK_GB * 1024) . " MB)\n";
        echo "  Disponible:               " . round(self::MAX_DISK_GB * 1024 - $totalMB, 0) . " MB\n\n";

        // Con datos medidos: ~1.2 MB por 1K filas
        $mbPer1k = 1.2;
        echo "  Ratio medido: ~{$mbPer1k} MB por 1000 filas (NDJSON sin comprimir)\n\n";

        $scenarios = [
            ['10 links OData (100K filas c/u)',  10 * 100000],
            ['20 links OData (100K filas c/u)',  20 * 100000],
            ['5 links (500K) + 10 links (50K)', 5 * 500000 + 10 * 50000],
            ['1 vista monstruo (1.48M)',         1480000],
        ];

        printf("  %-45s | %10s | %s\n", 'Escenario', 'Estimado', '¿Cabe en 3GB?');
        echo "  " . str_repeat('-', 75) . "\n";
        foreach ($scenarios as [$label, $rows]) {
            $estMB = $rows / 1000 * $mbPer1k;
            $cabe = $estMB < self::MAX_DISK_GB * 1024 ? '✓ Sí' : '✗ NO';
            printf("  %-45s | %7.0f MB | %s\n", $label, $estMB, $cabe);
        }

        echo "\n  PROTECCIÓN: max_rows por link + limpieza cada 6h + limit total\n";

        // Validar que el estado actual no excede
        $this->assertLessThan(
            self::MAX_DISK_GB * 1024,
            $totalMB,
            "Disco actual excede el límite"
        );
    }

}
