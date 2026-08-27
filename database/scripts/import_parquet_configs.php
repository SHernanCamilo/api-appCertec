<?php

/**
 * Script para importar masivamente las vistas de Graph-Fabric a bi_parquet_config.
 *
 * Ejecutar con:
 *   php artisan tinker database/scripts/import_parquet_configs.php
 *
 * O directamente:
 *   php artisan tinker --execute="require 'database/scripts/import_parquet_configs.php';"
 *
 * Comportamiento:
 *   - Consulta Graph-Fabric /api/r2/status para obtener las 766+ vistas
 *   - Inserta en bi_parquet_config con intervalo 30 min por defecto
 *   - NO sobreescribe las que ya esten configuradas
 *   - Vistas con "HojaQx" o "Censo" → 10 min, priority high
 *   - Schema "dc" → 5 min realtime
 *   - Schema "hg" → 15 min high
 */

use App\Models\BiParquetConfig;
use Illuminate\Support\Facades\Http;

echo "=== Importacion masiva de parquets desde Graph-Fabric ===\n\n";

$baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
$token   = config('fabric.token_admin', '');

echo "Conectando a: {$baseUrl}/api/r2/status\n";

try {
    $response = Http::timeout(30)->get("{$baseUrl}/api/r2/status", ['token' => $token]);

    if (!$response->successful()) {
        echo "ERROR: Graph-Fabric respondio con status {$response->status()}\n";
        echo $response->body() . "\n";
        exit(1);
    }
} catch (\Exception $e) {
    echo "ERROR: No se pudo conectar - {$e->getMessage()}\n";
    echo "\nAlternativa: Usar el JSON del doc cargue_vista_status_parquet.md\n";
    echo "Intentando con archivo local...\n\n";

    // Fallback: leer del MD si existe
    $mdPath = base_path('docs/cargue_vista_status_parquet.md');
    if (file_exists($mdPath)) {
        $content = file_get_contents($mdPath);
        $data = json_decode($content, true);
        if (!$data || !isset($data['data']['views'])) {
            echo "ERROR: No se pudo parsear el archivo MD como JSON.\n";
            exit(1);
        }
        $views = $data['data']['views'];
        echo "Cargando desde archivo local: " . count($views) . " vistas encontradas.\n\n";
    } else {
        echo "ERROR: No hay archivo local disponible.\n";
        exit(1);
    }
}

if (!isset($views)) {
    $data  = $response->json();
    $views = $data['data']['views'] ?? $data['views'] ?? [];
}

if (empty($views)) {
    echo "No se encontraron vistas en la respuesta.\n";
    exit(1);
}

echo "Total vistas en Graph-Fabric: " . count($views) . "\n\n";

$imported = 0;
$skipped  = 0;

foreach ($views as $view) {
    $schema   = $view['schema'] ?? '';
    $viewName = $view['view'] ?? '';

    if (!$schema || !$viewName) continue;

    // No sobreescribir las ya configuradas
    if (BiParquetConfig::forView($schema, $viewName)->exists()) {
        $skipped++;
        continue;
    }

    // Determinar intervalo segun patron
    $defaults = getDefaults($schema, $viewName);

    BiParquetConfig::create([
        'schema_name'          => $schema,
        'view_name'            => $viewName,
        'refresh_interval_min' => $defaults['interval'],
        'priority'             => $defaults['priority'],
        'group_name'           => $defaults['group'],
        'enabled'              => true,
    ]);

    $imported++;

    // Progreso cada 50
    if ($imported % 50 === 0) {
        echo "  ... {$imported} importadas\n";
    }
}

echo "\n=== Resultado ===\n";
echo "Importadas: {$imported}\n";
echo "Ya existian (skip): {$skipped}\n";
echo "Total en tabla: " . BiParquetConfig::count() . "\n";
echo "\nListo!\n";

// =========================================================================

function getDefaults(string $schema, string $viewName): array
{
    // HojaQx / Censo → 10 min
    if (str_contains($viewName, 'HojaQx') || str_contains($viewName, 'Censo')) {
        return ['interval' => 10, 'priority' => 'high', 'group' => 'censos'];
    }

    // Treasury (Tesorería) → 15 min, priority high
    if (str_contains($viewName, 'Treasury') || str_contains($viewName, 'Recibo') || str_contains($viewName, 'EgresoTesoreria')) {
        return ['interval' => 15, 'priority' => 'high', 'group' => 'financiero'];
    }

    // Cartera/Portfolio → 30 min
    if (str_contains($viewName, 'Portfolio') || str_contains($viewName, 'Cartera') || str_contains($viewName, 'Recaudo')) {
        return ['interval' => 30, 'priority' => 'medium', 'group' => 'financiero'];
    }

    // Ledger (contabilidad/libros) → 60 min
    if (str_contains($viewName, 'Ledger') || str_contains($viewName, 'Balance') || str_contains($viewName, 'Comprobante')) {
        return ['interval' => 60, 'priority' => 'medium', 'group' => 'financiero'];
    }

    // Billing (facturación) → 30 min
    if (str_contains($viewName, 'Billing') || str_contains($viewName, 'Factur')) {
        return ['interval' => 30, 'priority' => 'medium', 'group' => 'financiero'];
    }

    return match ($schema) {
        'dc'    => ['interval' => 5,  'priority' => 'realtime', 'group' => 'censos'],
        'hg'    => ['interval' => 15, 'priority' => 'high',     'group' => 'operativo'],
        'pt'    => ['interval' => 15, 'priority' => 'high',     'group' => 'financiero'],
        'ca'    => ['interval' => 30, 'priority' => 'medium',   'group' => 'financiero'],
        'co'    => ['interval' => 60, 'priority' => 'medium',   'group' => 'financiero'],
        'fr'    => ['interval' => 30, 'priority' => 'medium',   'group' => 'financiero'],
        'if'    => ['interval' => 60, 'priority' => 'low',      'group' => 'financiero'],
        default => ['interval' => 30, 'priority' => 'medium',   'group' => 'general'],
    };
}
