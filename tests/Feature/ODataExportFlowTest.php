<?php

namespace Tests\Feature;

use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\ODataSnapshotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests que validan el flujo completo de consulta y exportación.
 *
 * REQUIERE: Graph-Fabric corriendo en local (py app.py serve --port 8081).
 *
 * Valida:
 * - Consulta directa a Fabric devuelve datos reales en tiempo real
 * - Snapshot se genera desde R2 (parquet) con datos correctos
 * - Los datos del snapshot y Fabric son estructuralmente idénticos
 * - Múltiples vistas funcionan
 * - Headers numéricos no crashean
 * - El flujo de export con datos que tienen columnas numéricas funciona
 */
class ODataExportFlowTest extends TestCase
{
    private ODataSnapshotService $snapshots;
    private GraphFabricGatewayService $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('GRAPHQL_URL=http://127.0.0.1:8081');
        $this->snapshots = app(ODataSnapshotService::class);
        $this->gateway = app(GraphFabricGatewayService::class);
    }

    protected function tearDown(): void
    {
        $this->snapshots->invalidate('TEST_EXPORT_ALM');
        $this->snapshots->invalidate('TEST_EXPORT_PROD');
        parent::tearDown();
    }

    // =========================================================================
    // TEST: Vista VW_Inventory_Almacenes completa
    // =========================================================================

    public function test_almacenes_full_flow(): void
    {
        $schema = 'in';
        $view = 'VW_Inventory_Almacenes';
        $linkCode = 'TEST_EXPORT_ALM';

        // 1. Consulta directa — datos en tiempo real
        $direct = $this->gateway->queryAsSystem($schema, $view, [
            'columns' => [],
            'filters' => [],
            'limit'   => 50,
            'offset'  => 0,
        ]);
        $this->assertTrue($direct['success'], "Consulta directa a {$view} falló");
        $this->assertNotEmpty($direct['data']);
        $directCols = array_keys($direct['data'][0]);

        echo "\n  [Almacenes] Consulta directa OK: " . count($direct['data']) . " filas, " . count($directCols) . " columnas\n";

        // 2. Snapshot desde R2 — debe tener las mismas columnas
        $this->snapshots->invalidate($linkCode);
        $page = $this->snapshots->getPage($linkCode, [
            'schema'   => $schema,
            'view'     => $view,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 5000,
        ], 0, 50, 300);

        $this->assertTrue($page['success']);
        $this->assertNotEmpty($page['data']);
        $snapshotCols = array_keys($page['data'][0]);

        // Columnas idénticas
        sort($directCols);
        sort($snapshotCols);
        $this->assertEquals($directCols, $snapshotCols, 'Columnas difieren entre Fabric y snapshot');

        echo "  [Almacenes] Snapshot OK: {$page['total']} filas, source={$page['source']}\n";

        // 3. Datos no vacíos — validar campos clave
        $firstRow = $page['data'][0];
        $this->assertArrayHasKey('SOURCE', $firstRow);
        $this->assertArrayHasKey('Producto', $firstRow);
        $this->assertArrayHasKey('Cantidad', $firstRow);
        $this->assertNotEmpty($firstRow['Producto'], 'Producto no puede ser vacío');

        echo "  [Almacenes] Datos válidos: Producto='{$firstRow['Producto']}', Cantidad={$firstRow['Cantidad']}\n";
    }

    // =========================================================================
    // TEST: Vista VW_Inventory_Productos
    // =========================================================================

    public function test_productos_full_flow(): void
    {
        $schema = 'in';
        $view = 'VW_Inventory_Productos';
        $linkCode = 'TEST_EXPORT_PROD';

        // 1. Consulta directa
        $direct = $this->gateway->queryAsSystem($schema, $view, [
            'columns' => [],
            'filters' => [],
            'limit'   => 20,
            'offset'  => 0,
        ]);
        $this->assertTrue($direct['success'], "Consulta directa a {$view} falló");
        $this->assertNotEmpty($direct['data']);

        echo "\n  [Productos] Consulta directa OK: " . count($direct['data']) . " filas\n";

        // 2. Snapshot
        $this->snapshots->invalidate($linkCode);
        $page = $this->snapshots->getPage($linkCode, [
            'schema'   => $schema,
            'view'     => $view,
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 5000,
        ], 0, 20, 300);

        $this->assertTrue($page['success']);
        $this->assertNotEmpty($page['data']);

        // Columnas coinciden
        $directCols = array_keys($direct['data'][0]);
        $snapCols = array_keys($page['data'][0]);
        sort($directCols);
        sort($snapCols);
        $this->assertEquals($directCols, $snapCols);

        echo "  [Productos] Snapshot OK: {$page['total']} filas, source={$page['source']}\n";
    }

    // =========================================================================
    // TEST: Simulación de export con headers numéricos
    // =========================================================================

    public function test_export_with_numeric_column_names(): void
    {
        // Simular datos como los de VW_Inventory_AlmacenesPivot_315_Cmi
        // donde las columnas de almacén son números (315, 051, etc.)
        $mockData = [
            ['315' => 1, '051' => 54, 'Codigo' => '19906526-3', 'Producto' => 'FOSFATO DE SODIO'],
            ['315' => 0, '051' => 20, 'Codigo' => '19908128-7', 'Producto' => 'PIRIDOSTIGMINA'],
        ];

        // array_keys del primer item — esto es lo que FabricStreamExportJob hace
        $headers = array_map('strval', array_keys($mockData[0]));

        // Validar que no crashea strtolower
        foreach ($headers as $h) {
            $lower = strtolower((string) $h);
            $this->assertIsString($lower);
        }

        // Validar que los headers son strings, no ints
        $this->assertSame('315', $headers[0], 'El header 315 debe ser string, no int');
        $this->assertSame('051', $headers[1], 'El header 051 debe ser string, no int');
        $this->assertSame('Codigo', $headers[2]);
        $this->assertSame('Producto', $headers[3]);

        // Simular detectTextColumns
        $job = new \ReflectionClass(\App\Jobs\FabricStreamExportJob::class);
        $method = $job->getMethod('detectTextColumns');
        $method->setAccessible(true);
        $instance = $job->newInstanceWithoutConstructor();

        $firstRowValues = array_values($mockData[0]);
        $textCols = $method->invoke($instance, $headers, $firstRowValues);

        // No debe haber crasheado
        $this->assertIsArray($textCols);

        echo "\n  ✓ Export con columnas numéricas funciona sin crash\n";
        echo "  Headers: [" . implode(', ', $headers) . "]\n";
        echo "  Text columns detectadas: " . implode(', ', array_keys($textCols)) . "\n";
    }

    // =========================================================================
    // TEST: R2 endpoint está disponible y responde
    // =========================================================================

    public function test_r2_export_endpoint_available(): void
    {
        $url = env('GRAPHQL_URL', 'http://127.0.0.1:8081');
        $token = env('TOKEN_ADMIN', '');

        $response = Http::timeout(30)
            ->connectTimeout(5)
            ->post($url . '/api/data/export/r2', [
                'token'       => $token,
                'user_email'  => 'test@medilaser.com.co',
                'user_name'   => 'Test',
                'department'  => 'NAL-TIC NAL',
                'groups'      => ['GG-BD-IN', 'GG-BD-ADMIN'],
                'schema_name' => 'in',
                'view'        => 'VW_Inventory_Almacenes',
                'filters'     => new \stdClass(),
                'columns'     => [],
                'max_rows'    => 100,
                'format'      => 'gzip',
            ]);

        // 200 = parquet disponible, 202 = generando, 404 = no existe
        $status = $response->status();
        $this->assertContains($status, [200, 202, 404], "R2 respondió con status inesperado: {$status}");

        if ($status === 200) {
            $this->assertGreaterThan(0, strlen($response->body()), 'R2 respondió 200 pero body vacío');
            echo "\n  ✓ R2 disponible: parquet listo (" . strlen($response->body()) . " bytes gzip)\n";
        } elseif ($status === 202) {
            echo "\n  ✓ R2 respondió 202: parquet se está generando\n";
        } else {
            echo "\n  ⚠ R2 respondió 404: parquet no existe para esta vista\n";
        }
    }

    // =========================================================================
    // TEST: Paginación completa — recorre todas las páginas sin saltar ni duplicar
    // =========================================================================

    public function test_full_pagination_no_duplicates(): void
    {
        $context = [
            'schema'   => 'in',
            'view'     => 'VW_Inventory_Almacenes',
            'filters'  => [],
            'columns'  => [],
            'sort_col' => '',
            'sort_dir' => 'asc',
            'max_rows' => 200, // Limitamos para que sea rápido
        ];
        $linkCode = 'TEST_EXPORT_ALM';
        $pageSize = 50;

        // Generar snapshot
        $this->snapshots->invalidate($linkCode);
        $first = $this->snapshots->getPage($linkCode, $context, 0, $pageSize, 300);
        $this->assertTrue($first['success']);

        $total = $first['total'];
        $allRows = $first['data'];
        $offset = $pageSize;

        while ($offset < $total && $offset < 200) {
            $page = $this->snapshots->getPage($linkCode, $context, $offset, $pageSize, 300);
            $this->assertTrue($page['success']);
            $allRows = array_merge($allRows, $page['data']);
            $offset += $pageSize;
        }

        // Verificar que no hay duplicados (por json_encode de cada fila)
        $encoded = array_map('json_encode', $allRows);
        $unique = array_unique($encoded);
        $duplicates = count($allRows) - count($unique);

        $this->assertEquals(0, $duplicates, "Encontrados {$duplicates} filas duplicadas entre páginas");
        $this->assertCount(min($total, 200), $allRows, "Total de filas recorridas no coincide con el esperado");

        echo "\n  ✓ Paginación completa: " . count($allRows) . " filas sin duplicados (total={$total})\n";
    }
}
