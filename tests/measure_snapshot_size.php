<?php

/**
 * Script de medición: tamaño de snapshots NDJSON en disco.
 * Ejecutar: php tests/measure_snapshot_size.php
 *
 * Requiere Graph-Fabric en puerto 8081.
 */

putenv('GRAPHQL_URL=http://127.0.0.1:8081');
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$snapshots = app(App\Services\Fabric\ODataSnapshotService::class);
$dir = storage_path('app/odata_snapshots');

echo "═══════════════════════════════════════════════════════════════\n";
echo " MEDICIÓN DE TAMAÑO DE SNAPSHOTS NDJSON EN DISCO\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Test con distintos tamaños de max_rows
$tests = [
    ['label' => 'Almacenes 1K',   'schema' => 'in', 'view' => 'VW_Inventory_Almacenes', 'max' => 1000],
    ['label' => 'Almacenes 5K',   'schema' => 'in', 'view' => 'VW_Inventory_Almacenes', 'max' => 5000],
    ['label' => 'Productos 5K',   'schema' => 'in', 'view' => 'VW_Inventory_Productos', 'max' => 5000],
    ['label' => 'Productos 20K',  'schema' => 'in', 'view' => 'VW_Inventory_Productos', 'max' => 20000],
];

$results = [];

foreach ($tests as $test) {
    $code = 'MEASURE_' . md5($test['label']);
    $snapshots->invalidate($code);

    $t0 = microtime(true);
    $page = $snapshots->getPage($code, [
        'schema'   => $test['schema'],
        'view'     => $test['view'],
        'filters'  => [],
        'columns'  => [],
        'sort_col' => '',
        'sort_dir' => 'asc',
        'max_rows' => $test['max'],
    ], 0, 1, 300);
    $elapsed = round(microtime(true) - $t0, 2);

    $rows = $page['total'] ?? 0;
    $source = $page['source'] ?? '?';

    // Buscar el archivo
    $files = glob($dir . '/' . $code . '_*.ndjson') ?: [];
    $sizeMB = 0;
    if (!empty($files)) {
        $sizeMB = filesize($files[0]) / 1048576;
    }

    $mbPer1k = $rows > 0 ? $sizeMB / ($rows / 1000) : 0;

    $results[] = [
        'label'   => $test['label'],
        'rows'    => $rows,
        'sizeMB'  => round($sizeMB, 2),
        'mbPer1k' => round($mbPer1k, 3),
        'source'  => $source,
        'time'    => $elapsed,
    ];

    printf("  %-20s | %6d filas | %7.2f MB | %.3f MB/1K filas | %s | %ss\n",
        $test['label'], $rows, $sizeMB, $mbPer1k, $source, $elapsed);

    // Limpiar
    $snapshots->invalidate($code);
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " PROYECCIÓN PARA PRODUCCIÓN\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Promedio de MB por 1K filas
$avgMbPer1k = array_sum(array_column($results, 'mbPer1k')) / count($results);
echo "  Promedio: {$avgMbPer1k} MB por cada 1000 filas\n\n";

// Proyección para vistas típicas de producción
$prodViews = [
    ['Vista HC Atenciones (626K filas)',  626000],
    ['Vista Almacenes Pivot (50K filas)', 50000],
    ['Vista Facturación (200K filas)',    200000],
    ['Vista Inventario (100K filas)',     100000],
    ['10 vistas OData activas (promedio 100K)', 100000 * 10],
    ['20 vistas OData activas (promedio 100K)', 100000 * 20],
];

echo sprintf("  %-50s | %10s\n", 'Escenario', 'Disco estimado');
echo "  " . str_repeat('-', 65) . "\n";
foreach ($prodViews as [$label, $rows]) {
    $estMB = $rows / 1000 * $avgMbPer1k;
    $estGB = $estMB / 1024;
    if ($estGB >= 1) {
        echo sprintf("  %-50s | %7.2f GB\n", $label, $estGB);
    } else {
        echo sprintf("  %-50s | %7.0f MB\n", $label, $estMB);
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " DISCO VPS DISPONIBLE (estimado)\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// En la VPS: du -sh storage/app/odata_snapshots/ mostraría el uso real
// Localmente medimos cuánto ocupa el directorio ahora
$totalLocal = 0;
foreach (glob($dir . '/*.ndjson') ?: [] as $f) {
    $totalLocal += filesize($f);
}
echo "  Archivos NDJSON en disco local ahora: " . round($totalLocal / 1048576, 2) . " MB\n";
echo "  (Se eliminan cada 6 horas por odata:snapshot-cleanup)\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo " CONCLUSIÓN\n";
echo "═══════════════════════════════════════════════════════════════\n\n";
echo "  Con {$avgMbPer1k} MB/1K filas y limpieza cada 6h:\n";
echo "  - 10 vistas activas de ~100K → " . round(100 * 10 * $avgMbPer1k / 1024, 2) . " GB pico\n";
echo "  - 20 vistas activas de ~100K → " . round(100 * 20 * $avgMbPer1k / 1024, 2) . " GB pico\n";
echo "  - La VPS tiene ~100 GB de disco (verificar con df -h)\n";
echo "  - RIESGO: bajo. Incluso 20 vistas grandes = pocos GB.\n";
echo "  - MITIGACIÓN: limpieza cada 6h + límite max_rows por link.\n\n";
