<?php

/**
 * Rebalancea los intervalos de refresh de bi_parquet_config segun la naturaleza
 * REAL de cada vista, para dejar de represar el scheduler de Graph-Fabric.
 *
 * Problema: 287 vistas marcadas 'realtime' (cada 5 min) pero la infra solo
 * regenera ~30-40 a esa frecuencia. El resto se acumula como 'stale'.
 *
 * Objetivo: bajar realtime a ~40-60 (solo censos/urgencias reales), el resto
 * a operativo (15-60 min) o analitico (120-480 min).
 *
 * Reglas provistas por el equipo de Graph-Fabric (patron del nombre de vista).
 *
 * Ejecutar:
 *   php artisan tinker database/scripts/rebalance_parquet_intervals.php
 *
 * Luego sincronizar con Graph-Fabric:
 *   php artisan fabric:sync-parquet-config
 *
 * IMPORTANTE: requiere la migracion 2026_08_27_000001_expand_parquet_priority_enum
 * que agrega 'operativo' y 'analitico' al ENUM de priority.
 */

use App\Models\BiParquetConfig;

echo "=== Rebalanceo de intervalos de parquet (reglas Graph-Fabric) ===\n\n";

$rules = [
    // ── REALTIME real: solo censos y tableros que cambian con cada ingreso/egreso ──
    ['patron' => 'VW_Censo',                 'intervalo' => 5,   'prioridad' => 'realtime', 'grupo' => 'censos'],
    ['patron' => 'VW_HC_TableroUrgencias',   'intervalo' => 5,   'prioridad' => 'realtime', 'grupo' => 'censos'],
    ['patron' => 'VW_AD_Censo_Trazabilidad', 'intervalo' => 10,  'prioridad' => 'realtime', 'grupo' => 'censos'],
    ['patron' => 'VW_HC_CensoHistorico',     'intervalo' => 10,  'prioridad' => 'realtime', 'grupo' => 'censos'],

    // ── OPERATIVO: se consultan seguido pero cambian cada 15-30 min ──
    ['patron' => 'VW_HC_Egresos',            'intervalo' => 30,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_HC_ClasificacionTriage','intervalo' => 15,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_HC_ConsultasTriage',    'intervalo' => 30,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_AG_Agendas',            'intervalo' => 30,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_AG_Citas',              'intervalo' => 30,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_Inventory',             'intervalo' => 60,  'prioridad' => 'operativo', 'grupo' => 'operativo'],

    // ── MEDIO: historias clinicas, evoluciones (cambian cada hora) ──
    ['patron' => 'VW_HC_Evoluciones',        'intervalo' => 60,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_HC_HistoriasClinicas',  'intervalo' => 60,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_HC_Atenciones',         'intervalo' => 120, 'prioridad' => 'analitico', 'grupo' => 'analitico'], // las lentas de 40min
    ['patron' => 'VW_HC_HojaQx',             'intervalo' => 60,  'prioridad' => 'operativo', 'grupo' => 'operativo'],
    ['patron' => 'VW_HC_Procedimientos',     'intervalo' => 60,  'prioridad' => 'operativo', 'grupo' => 'operativo'],

    // ── ANALITICO: cartera, facturacion, financieros (cambian cada 2-4h) ──
    ['patron' => 'VW_Portfolio',             'intervalo' => 120, 'prioridad' => 'analitico', 'grupo' => 'financiero'],
    ['patron' => 'VW_Billing',               'intervalo' => 120, 'prioridad' => 'analitico', 'grupo' => 'financiero'],
    ['patron' => 'VW_Ledger',                'intervalo' => 240, 'prioridad' => 'analitico', 'grupo' => 'financiero'],
    ['patron' => 'VW_MedicalFees',           'intervalo' => 120, 'prioridad' => 'analitico', 'grupo' => 'financiero'],
    ['patron' => 'VW_Financiera',            'intervalo' => 240, 'prioridad' => 'analitico', 'grupo' => 'financiero'],
    ['patron' => 'VW_Payroll',               'intervalo' => 240, 'prioridad' => 'analitico', 'grupo' => 'financiero'],
    ['patron' => 'VW_Treasury',              'intervalo' => 60,  'prioridad' => 'operativo', 'grupo' => 'financiero'],
    ['patron' => 'VW_Fixed',                 'intervalo' => 480, 'prioridad' => 'analitico', 'grupo' => 'analitico'], // activos fijos, casi no cambian
];

$actualizadas = 0;

foreach ($rules as $rule) {
    $count = BiParquetConfig::where('view_name', 'like', $rule['patron'] . '%')
        ->update([
            'refresh_interval_min' => $rule['intervalo'],
            'priority'             => $rule['prioridad'],
            'group_name'           => $rule['grupo'],
        ]);

    printf("  %-32s %4d vistas -> %3dmin (%s)\n",
        $rule['patron'], $count, $rule['intervalo'], $rule['prioridad']);
    $actualizadas += $count;
}

echo "\nTotal actualizadas: {$actualizadas}\n\n";

// Resumen de distribucion por prioridad
echo "Distribucion por prioridad:\n";
$dist = BiParquetConfig::selectRaw('priority, count(*) as total')
    ->groupBy('priority')
    ->orderByRaw("FIELD(priority,'realtime','operativo','analitico','high','medium','low','manual')")
    ->get();
foreach ($dist as $d) {
    printf("  %-12s %4d vistas\n", $d->priority, $d->total);
}

echo "\nDistribucion por intervalo:\n";
$distI = BiParquetConfig::selectRaw('refresh_interval_min, count(*) as total')
    ->groupBy('refresh_interval_min')
    ->orderBy('refresh_interval_min')
    ->get();
foreach ($distI as $d) {
    printf("  %4d min: %4d vistas\n", $d->refresh_interval_min, $d->total);
}

echo "\n>>> Ahora ejecuta: php artisan fabric:sync-parquet-config\n";
echo "Listo!\n";
