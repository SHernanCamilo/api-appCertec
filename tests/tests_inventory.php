<?php

/**
 * Script de pruebas para verificar el módulo de Inventario
 * Ejecutar con: php artisan tinker < tests_inventory.php
 */

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n" . str_repeat('=', 70) . "\n";
echo "  PRUEBAS DEL MÓDULO DE INVENTARIO - api-appCertec\n";
echo str_repeat('=', 70) . "\n\n";

$passed = 0;
$failed = 0;

function test($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ PASS: {$name}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$name}";
        if ($detail) echo " — {$detail}";
        echo "\n";
        $failed++;
    }
}

// =========================================================================
// 1. VERIFICAR TABLAS
// =========================================================================
echo "── 1. Verificación de Tablas ──────────────────────────────────────\n";

$tables = [
    'inv_productos',
    'inv_secuencias',
    'inv_pedidos',
    'inv_pedido_detalles',
    'inv_ordenes_compra',
    'inv_orden_compra_detalles',
    'inv_recepciones',
    'inv_recepcion_detalles',
];

foreach ($tables as $table) {
    test("Tabla '{$table}' existe", Schema::hasTable($table));
}

// =========================================================================
// 2. VERIFICAR COLUMNAS CLAVE
// =========================================================================
echo "\n── 2. Columnas clave en tablas ───────────────────────────────────\n";

test("inv_recepcion_detalles tiene 'codigo_sanitario'", Schema::hasColumn('inv_recepcion_detalles', 'codigo_sanitario'));
test("inv_recepcion_detalles tiene 'aspecto_cumple'", Schema::hasColumn('inv_recepcion_detalles', 'aspecto_cumple'));
test("inv_recepcion_detalles tiene 'concepto_recepcion'", Schema::hasColumn('inv_recepcion_detalles', 'concepto_recepcion'));
test("inv_recepcion_detalles tiene 'es_medicamento_vital'", Schema::hasColumn('inv_recepcion_detalles', 'es_medicamento_vital'));
test("inv_recepcion_detalles tiene 'mvd_ium'", Schema::hasColumn('inv_recepcion_detalles', 'mvd_ium'));
test("inv_pedidos tiene 'estado'", Schema::hasColumn('inv_pedidos', 'estado'));
test("inv_ordenes_compra tiene 'numero_orden_compra'", Schema::hasColumn('inv_ordenes_compra', 'numero_orden_compra'));

// =========================================================================
// 3. VERIFICAR MODELOS (instanciación)
// =========================================================================
echo "\n── 3. Instanciación de Modelos ───────────────────────────────────\n";

$models = [
    'App\Models\Inventory\InvProducto',
    'App\Models\Inventory\InvPedido',
    'App\Models\Inventory\InvPedidoDetalle',
    'App\Models\Inventory\InvOrdenCompra',
    'App\Models\Inventory\InvOrdenCompraDetalle',
    'App\Models\Inventory\InvRecepcion',
    'App\Models\Inventory\InvRecepcionDetalle',
    'App\Models\Inventory\InvSecuencia',
    'App\Models\Inventory\External\IndigoOrdenCompra',
];

foreach ($models as $model) {
    $shortName = class_basename($model);
    try {
        $instance = new $model();
        test("Modelo '{$shortName}' instanciable", true);
    } catch (\Throwable $e) {
        test("Modelo '{$shortName}' instanciable", false, $e->getMessage());
    }
}

// =========================================================================
// 4. VERIFICAR SERVICIOS (resolución via IoC)
// =========================================================================
echo "\n── 4. Resolución de Servicios ────────────────────────────────────\n";

$services = [
    'App\Services\Inventory\Pharmacy\InvimaService',
    'App\Services\Inventory\GraphQLClientService',
    'App\Services\Inventory\Pharmacy\InvProductoService',
    'App\Services\Inventory\Pharmacy\InvPedidoService',
    'App\Services\Inventory\Pharmacy\InvOrdenCompraService',
    'App\Services\Inventory\Pharmacy\InvRecepcionService',
];

foreach ($services as $service) {
    $shortName = class_basename($service);
    try {
        $instance = app($service);
        test("Servicio '{$shortName}' resuelto", $instance !== null);
    } catch (\Throwable $e) {
        test("Servicio '{$shortName}' resuelto", false, $e->getMessage());
    }
}

// =========================================================================
// 5. VERIFICAR CONTROLADORES (resolución)
// =========================================================================
echo "\n── 5. Resolución de Controladores ────────────────────────────────\n";

$controllers = [
    'App\Http\Controllers\Inventory\InvProductoController',
    'App\Http\Controllers\Inventory\InvPedidoController',
    'App\Http\Controllers\Inventory\InvOrdenCompraController',
    'App\Http\Controllers\Inventory\InvRecepcionController',
];

foreach ($controllers as $ctrl) {
    $shortName = class_basename($ctrl);
    try {
        $instance = app($ctrl);
        test("Controlador '{$shortName}' resuelto", $instance !== null);
    } catch (\Throwable $e) {
        test("Controlador '{$shortName}' resuelto", false, $e->getMessage());
    }
}

// =========================================================================
// 6. PRUEBA FUNCIONAL: GraphQL Client
// =========================================================================
echo "\n── 6. Prueba GraphQL Client (Graph-Fabric) ──────────────────────\n";

try {
    $graphClient = app('App\Services\Inventory\GraphQLClientService');
    $result = $graphClient->getProducts(['limit' => 3]);
    
    if (isset($result['success']) && $result['success'] === true) {
        test("GraphQL getProducts() responde OK", true);
        $count = count($result['data'] ?? []);
        test("GraphQL devuelve datos ({$count} productos)", $count > 0);
        if ($count > 0) {
            $first = $result['data'][0];
            echo "     → Ejemplo: código={$first['codigo']}, nombre=" . substr($first['nombre'] ?? '?', 0, 40) . "\n";
        }
    } else {
        test("GraphQL getProducts() responde OK", false, $result['message'] ?? 'Sin mensaje');
    }
} catch (\Throwable $e) {
    test("GraphQL getProducts()", false, $e->getMessage());
    echo "     ⚠️  Asegúrate de que el servicio Graph-Fabric esté corriendo en " . env('GRAPHQL_URL') . "\n";
}

// =========================================================================
// 7. PRUEBA FUNCIONAL: INVIMA
// =========================================================================
echo "\n── 7. Prueba INVIMA (datos.gov.co) ──────────────────────────────\n";

try {
    $invima = app('App\Services\Inventory\Pharmacy\InvimaService');
    $result = $invima->searchProduct('DOLEX');
    
    if (isset($result['success']) && $result['success'] === true) {
        test("INVIMA searchProduct('DOLEX') responde OK", true);
        $count = count($result['data'] ?? []);
        test("INVIMA devuelve resultados ({$count} registros)", $count > 0);
    } else {
        test("INVIMA searchProduct('DOLEX') responde OK", false, $result['message'] ?? 'Sin datos');
    }
} catch (\Throwable $e) {
    test("INVIMA searchProduct()", false, $e->getMessage());
}

// =========================================================================
// 8. VERIFICAR VARIABLES DE ENTORNO
// =========================================================================
echo "\n── 8. Variables de entorno configuradas ──────────────────────────\n";

test("GRAPHQL_URL definida", env('GRAPHQL_URL') !== null, 'Valor: ' . (env('GRAPHQL_URL') ?: 'NO DEFINIDA'));
test("GRAPHQL_API_KEY definida", env('GRAPHQL_API_KEY') !== null);
test("MSSQL_PURCHASEORDER_HOST definida", env('MSSQL_PURCHASEORDER_HOST') !== null);
test("MSSQL_PURCHASEORDER_DB definida", env('MSSQL_PURCHASEORDER_DB') !== null);

// =========================================================================
// RESUMEN
// =========================================================================
echo "\n" . str_repeat('=', 70) . "\n";
$total = $passed + $failed;
echo "  RESULTADO: {$passed}/{$total} pruebas pasaron";
if ($failed > 0) {
    echo " ({$failed} fallaron)";
}
echo "\n" . str_repeat('=', 70) . "\n\n";

exit($failed > 0 ? 1 : 0);
