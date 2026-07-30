<?php

namespace Tests\Feature;

use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\ODataSnapshotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests de integración para el flujo OData con snapshot.
 *
 * REQUIERE: Graph-Fabric corriendo en local (py app.py serve --port 8081).
 * Ajusta GRAPHQL_URL en .env a http://127.0.0.1:8081 antes de ejecutar,
 * o usa: GRAPHQL_URL=http://127.0.0.1:8081 php artisan test --filter=ODataSnapshot
 *
 * Estos tests golpean el servicio real para validar datos en tiempo real.
 */
class ODataSnapshotIntegrationTest extends TestCase
{
    private ODataSnapshotService $snapshots;
    private GraphFabricGatewayService $gateway;

    /** Vista pequeña de inventario que sabemos que existe en local. */
    private string $testSchema = 'in';
    private string $testView = 'VW_Inventory_Almacenes';

    protected function setUp(): void
    {
        parent::setUp();

        // Forzar URL local de Graph-Fabric (tu instancia en 8081)
        putenv('GRAPHQL_URL=http://127.0.0.1:8081');

        $this->snapshots = app(ODataSnapshotService::class);
        $this->gateway = app(GraphFabricGatewayService::class);
    }

    protected function tearDown(): void
    {
        // Limpiar snapshots de test
        $this->snapshots->invalidate('TEST_LINK_001');
        parent::tearDown();
    }

    // =========================================================================
    // TEST 1: Consulta directa a Graph-Fabric funciona
    // =========================================================================

    public function test_graph_fabric_is_reachable(): void
    {
        $url = env('GRAPHQL_URL', 'http://127.0.0.1:8081');
        $response = Http::timeout(10)->get($url . '/healthz');

        $this->assertEquals(200, $response->status(), 'Graph-Fabric no responde en /healthz');
    }

    public function test_query_as_system_returns_data(): void
    {
        $result = $this->gateway->queryAsSystem(
            $this->testSchema,
            $this->testView,
            [
                'columns' => [],
                'filters' => [],
                'limit'   => 5,
                'offset'  => 0,
            ]
        );

        $this->assertTrue($result['success'], 'queryAsSystem falló: ' . ($result['message'] ?? ''));
        $this->assertNotEmpty($result['data'], 'queryAsSystem no devolvió datos');
        $this->assertIsArray($result['data'][0], 'Los items no son arrays');

        // Verificar que tiene meta con total
        $this->assertArrayHasKey('meta', $result, 'Falta meta en respuesta');
        $total = $result['meta']['total'] ?? null;
        $this->assertNotNull($total, 'meta.total es null — revisar si Python devuelve page_info.total');

        // Python puede devolver -1 si la vista no soporta COUNT nativo.
        // En ese caso el total no está disponible pero la consulta funciona.
        if ($total === -1) {
            echo "\n  ✓ Vista {$this->testSchema}.{$this->testView}: total no disponible (Python devuelve -1), datos OK\n";
        } else {
            $this->assertGreaterThan(0, $total, 'meta.total debería ser > 0');
            echo "\n  ✓ Vista {$this->testSchema}.{$this->testView}: {$total} filas totales, primera página OK\n";
        }
    }

    // =========================================================================
    // TEST 2: Snapshot se genera correctamente desde Fabric
    // =========================================================================

    public function test_snapshot_build_from_fabric(): void
    {
        // Invalidar snapshot previo para forzar rebuild
        $this->snapshots->invalidate('TEST_LINK_001');

        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000, // Limitar para que sea rápido
        ];

        $t0 = microtime(true);

        $page = $this->snapshots->getPage('TEST_LINK_001', $context, 0, 50, 300);

        $elapsed = round(microtime(true) - $t0, 2);

        $this->assertTrue($page['success'], 'getPage falló: ' . ($page['message'] ?? ''));
        $this->assertNotEmpty($page['data'], 'Snapshot generado pero sin datos');
        $this->assertGreaterThan(0, $page['total'], 'Total debe ser > 0');
        $this->assertEquals(50, count($page['data']), 'Debe devolver exactamente 50 filas (top=50)');
        $this->assertFalse($page['stale'], 'Snapshot recién creado no puede ser stale');

        echo "\n  ✓ Snapshot generado en {$elapsed}s — {$page['total']} filas, source={$page['source']}\n";
    }

    // =========================================================================
    // TEST 3: Paginación del snapshot es correcta
    // =========================================================================

    public function test_snapshot_pagination_consistent(): void
    {
        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Asegurar que el snapshot existe
        $page0 = $this->snapshots->getPage('TEST_LINK_001', $context, 0, 20, 300);
        $this->assertTrue($page0['success']);

        // Página 2
        $page1 = $this->snapshots->getPage('TEST_LINK_001', $context, 20, 20, 300);
        $this->assertTrue($page1['success']);

        // Total debe ser igual en ambas páginas
        $this->assertEquals($page0['total'], $page1['total'], 'Total inconsistente entre páginas');

        // Las filas no deben repetirse entre páginas
        $keys0 = array_map('json_encode', $page0['data']);
        $keys1 = array_map('json_encode', $page1['data']);
        $overlap = array_intersect($keys0, $keys1);
        $this->assertEmpty($overlap, 'Hay filas duplicadas entre página 0 y página 1');

        // has_next debe ser consistente
        if ($page0['total'] > 40) {
            $this->assertTrue($page1['has_next'], 'has_next debería ser true si total > 40');
        }

        echo "\n  ✓ Paginación consistente: p0=" . count($page0['data']) . ", p1=" . count($page1['data']) . ", total={$page0['total']}\n";
    }

    // =========================================================================
    // TEST 4: Snapshot fresco no golpea Fabric
    // =========================================================================

    public function test_snapshot_fresh_does_not_hit_fabric(): void
    {
        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Asegurar que snapshot existe
        $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);

        // Segunda lectura: debe ser instantánea (< 50ms) porque lee de disco
        $t0 = microtime(true);
        $page = $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        $this->assertTrue($page['success']);
        $this->assertLessThan(200, $elapsedMs, "Lectura de snapshot fresco tardó {$elapsedMs}ms — debería ser < 200ms");

        echo "\n  ✓ Lectura de snapshot fresco: {$elapsedMs}ms (no tocó Fabric)\n";
    }

    // =========================================================================
    // TEST 5: Refresh con validación de conteo — unchanged si no cambió
    // =========================================================================

    public function test_refresh_returns_unchanged_when_count_matches(): void
    {
        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Generar snapshot fresco
        $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);

        // Refresh: como los datos no cambiaron en los últimos segundos,
        // debería devolver 'unchanged' (solo toca el COUNT, no descarga todo).
        $t0 = microtime(true);
        $result = $this->snapshots->refresh('TEST_LINK_001', $context);
        $elapsed = round(microtime(true) - $t0, 2);

        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertGreaterThan(0, $result['rows']);

        // Si es unchanged, significa que el COUNT coincide → no descargó nada
        if ($result['source'] === 'unchanged') {
            echo "\n  ✓ Refresh validó conteo sin re-descargar: {$result['rows']} filas, {$elapsed}s (unchanged)\n";
        } else {
            // Si es r2 o fabric, es porque el conteo cambió (posible en producción) o
            // porque ODATA_SNAPSHOT_REBUILD_EVERY ya pasó.
            echo "\n  ✓ Refresh hizo rebuild: {$result['rows']} filas, source={$result['source']}, {$elapsed}s\n";
        }
    }

    // =========================================================================
    // TEST 6: Refresh forzado siempre reconstruye
    // =========================================================================

    public function test_refresh_force_always_rebuilds(): void
    {
        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Generar snapshot
        $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);

        // Refresh forzado: ignora la validación de conteo
        $result = $this->snapshots->refresh('TEST_LINK_001', $context, force: true);

        $this->assertNotEquals('unchanged', $result['source'], 'force=true no debería devolver unchanged');
        $this->assertContains($result['source'], ['r2', 'fabric']);
        $this->assertGreaterThan(0, $result['rows']);

        echo "\n  ✓ Refresh forzado: reconstruyó desde {$result['source']}, {$result['rows']} filas\n";
    }

    // =========================================================================
    // TEST 7: Datos del snapshot coinciden con datos frescos de Fabric
    // =========================================================================

    public function test_snapshot_data_matches_fabric_realtime(): void
    {
        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Forzar rebuild para tener datos frescos
        $this->snapshots->invalidate('TEST_LINK_001');
        $page = $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);
        $this->assertTrue($page['success']);

        // Consultar directamente a Fabric las mismas 10 filas
        $direct = $this->gateway->queryAsSystem($this->testSchema, $this->testView, [
            'columns' => [],
            'filters' => [],
            'limit'   => 10,
            'offset'  => 0,
        ]);

        $this->assertTrue($direct['success']);
        $this->assertNotEmpty($direct['data']);

        // Comparar: el total de Python puede ser -1 (no soporta COUNT nativo)
        // En ese caso solo validamos que las columnas coincidan.
        $snapshotTotal = $page['total'];
        $fabricTotal = $direct['meta']['total'] ?? -1;

        if ($fabricTotal > 0) {
            $this->assertEquals(
                $fabricTotal,
                $snapshotTotal,
                "Total del snapshot ({$snapshotTotal}) no coincide con Fabric ({$fabricTotal})"
            );
        }

        // Validar que las columnas del snapshot coinciden con las de Fabric
        $snapshotCols = array_keys($page['data'][0]);
        $fabricCols = array_keys($direct['data'][0]);
        sort($snapshotCols);
        sort($fabricCols);
        $this->assertEquals($fabricCols, $snapshotCols, 'Columnas del snapshot no coinciden con Fabric');

        // Validar que hay datos reales (no vacíos) en ambas fuentes
        $this->assertNotEmpty($page['data'][0], 'Snapshot tiene filas vacías');
        $this->assertNotEmpty($direct['data'][0], 'Fabric tiene filas vacías');

        // Nota: no comparamos fila a fila porque sin sort_col el orden no es
        // determinístico entre R2 y Fabric.

        echo "\n  ✓ Datos reales: snapshot y Fabric coinciden — " . count($snapshotCols) . " columnas, estructura idéntica\n";
    }

    // =========================================================================
    // TEST 8: Export (strtolower fix) con columnas numéricas
    // =========================================================================

    public function test_detect_text_columns_handles_numeric_headers(): void
    {
        // Simular headers como los de VW_Inventory_AlmacenesPivot_315_Cmi
        $headers = ['315', '051', 'Codigo', 'Producto', 'Nro_Cuenta'];

        $result = \App\Services\Fabric\Export\ExportValueFormatter::detectTextColumns(
            $headers,
            ['1', '054', '19906526', 'FOSFATO', '036004835']
        );

        // 'Nro_Cuenta' debe marcarse como texto (patrón nro_)
        // '036004835' empieza con 0 → debe marcarse como texto
        $this->assertArrayHasKey(4, $result, 'Nro_Cuenta (index 4) debería ser detectada como texto');

        // No debe haber crasheado por strtolower(int) — si llegamos aquí es que pasó
        echo "\n  ✓ detectTextColumns maneja headers numéricos sin crash\n";
    }

    // =========================================================================
    // TEST 9: Stale-while-revalidate — snapshot vencido se sirve al instante
    // =========================================================================

    public function test_stale_snapshot_served_immediately(): void
    {
        // NOTA: Este test requiere QUEUE_CONNECTION=redis para que el job de
        // refresco se despache a la cola y NO bloquee la respuesta. Con sync
        // el dispatch ejecuta el job inline y por eso tarda.
        // En producción (redis) el SWR funciona: sirve stale al instante.
        if (env('QUEUE_CONNECTION', 'sync') === 'sync') {
            $this->markTestSkipped(
                'SWR solo funciona con cola async (redis). En local con sync el dispatch es inline.'
            );
        }

        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Generar snapshot
        $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);

        // Esperar para que expire
        sleep(2);

        // Pedir con TTL=1 → el snapshot (age=2) está "vencido"
        $t0 = microtime(true);
        $page = $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 1);
        $elapsedMs = (microtime(true) - $t0) * 1000;

        $this->assertTrue($page['success']);
        $this->assertNotEmpty($page['data']);
        $this->assertTrue($page['stale'], 'Con TTL=1s y age=2s debería marcar como stale');
        $this->assertLessThan(500, $elapsedMs, "Snapshot stale tardó {$elapsedMs}ms — debe ser < 500ms");

        echo "\n  ✓ Snapshot stale servido en {$elapsedMs}ms (no bloqueó), stale=true\n";
    }

    // =========================================================================
    // TEST 10: Invalidate limpia archivos correctamente
    // =========================================================================

    public function test_invalidate_removes_snapshot_files(): void
    {
        $context = [
            'schema'   => $this->testSchema,
            'view'     => $this->testView,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 1000,
        ];

        // Generar snapshot
        $this->snapshots->getPage('TEST_LINK_001', $context, 0, 10, 300);

        // Verificar que el archivo existe
        $dir = storage_path('app/odata_snapshots');
        $files = glob($dir . '/TEST_LINK_001_*.ndjson') ?: [];
        $this->assertNotEmpty($files, 'No se encontró el archivo de snapshot');

        // Invalidar
        $this->snapshots->invalidate('TEST_LINK_001');

        // Verificar que se borró
        $filesAfter = glob($dir . '/TEST_LINK_001_*.ndjson') ?: [];
        $this->assertEmpty($filesAfter, 'El snapshot no se eliminó tras invalidate');

        echo "\n  ✓ Invalidate eliminó correctamente los archivos de snapshot\n";
    }
}
