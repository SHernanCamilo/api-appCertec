<?php

/**
 * Rebalancea los intervalos de refresh para evitar represar Graph-Fabric.
 *
 * Problema: con 762 vistas a intervalos agresivos (30 min), Graph no alcanza
 * a regenerarlas todas y se represa. La mayoría de vistas financieras/históricas
 * NO cambian cada 30 min.
 *
 * Solución: intervalos realistas según la naturaleza del dato.
 *   - Censos / operativo crítico     → 15 min  (realtime/high)
 *   - Treasury / caja                → 30 min  (high)
 *   - Agendas / atenciones           → 60 min  (medium)
 *   - Cartera / facturación          → 120 min (medium)
 *   - Ledger / balances / históricos → 240 min (low)
 *
 * Ejecutar:
 *   php artisan tinker database/scripts/rebalance_parquet_intervals.php
 *
 * Luego sincronizar:
 *   php artisan fabric:sync-parquet-config
 */

use App\Models\BiParquetConfig;

echo "=== Rebalanceo de intervalos de parquet ===\n\n";

$rules = [
    // patron en nombre => [interval_min, priority]
    'Censo'        => [15,  'realtime'],
    'HojaQx'       => [15,  'high'],
    'Treasury'     => [30,  'high'],
    'Recibo'       => [30,  'high'],
    'Caja'         => [30,  'high'],
    'Agenda'       => [60,  'medium'],
    'Cita'         => [60,  'medium'],
    'Atencion'     => [60,  'medium'],
    'Portfolio'    => [120, 'medium'],
    'Cartera'      => [120, 'medium'],
    'Recaudo'      => [120, 'medium'],
    'Billing'      => [120, 'medium'],
    'Factur'       => [120, 'medium'],
    'Ledger'       => [240, 'low'],
    'Balance'      => [240, 'low'],
    'Comprobante'  => [240, 'low'],
    'EstadoResult' => [240, 'low'],
];

$updated = 0;
$configs = BiParquetConfig::all();

foreach ($configs as $c) {
    $newInterval = null;
    $newPriority = null;

    // Buscar primera regla que matchee el nombre de la vista
    foreach ($rules as $pattern => [$interval, $priority]) {
        if (stripos($c->view_name, $pattern) !== false) {
            $newInterval = $interval;
            $newPriority = $priority;
            break;
        }
    }

    // Si no matcheo ningun patron, usar default por schema
    if ($newInterval === null) {
        [$newInterval, $newPriority] = match ($c->schema_name) {
            'dc'    => [15,  'realtime'],
            'hg'    => [30,  'high'],
            'pt'    => [30,  'high'],
            'aa'    => [60,  'medium'],
            default => [120, 'medium'],
        };
    }

    // Solo actualizar si cambio
    if ($c->refresh_interval_min != $newInterval || $c->priority != $newPriority) {
        $c->update([
            'refresh_interval_min' => $newInterval,
            'priority'             => $newPriority,
        ]);
        $updated++;
    }
}

echo "Vistas actualizadas: {$updated} de {$configs->count()}\n\n";

// Resumen de distribucion
echo "Distribucion de intervalos:\n";
$dist = BiParquetConfig::selectRaw('refresh_interval_min, count(*) as total')
    ->groupBy('refresh_interval_min')
    ->orderBy('refresh_interval_min')
    ->get();

foreach ($dist as $d) {
    echo "  {$d->refresh_interval_min} min: {$d->total} vistas\n";
}

echo "\nAhora ejecuta: php artisan fabric:sync-parquet-config\n";
echo "Listo!\n";
