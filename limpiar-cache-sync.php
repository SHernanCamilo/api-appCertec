<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🧹 Limpiando cache de sincronizaciones...\n\n";

// Buscar todas las claves de cache relacionadas con sincronizaciones
$cacheStore = cache()->getStore();
$prefix = config('cache.prefix') ? config('cache.prefix') . ':' : '';

echo "📋 Buscando sincronizaciones en cache...\n";

// Leer el archivo de logs para encontrar sync_ids
$logPath = storage_path('logs/ActivosGLPI.log');
$syncIds = [];

if (file_exists($logPath)) {
    $lines = file($logPath);
    $recentLines = array_slice($lines, -200); // Últimas 200 líneas
    
    foreach ($recentLines as $line) {
        if (preg_match('/sync_id.*?(sync_\d+_[a-z0-9]+)/', $line, $matches)) {
            $syncIds[] = $matches[1];
        }
    }
    
    $syncIds = array_unique($syncIds);
}

if (empty($syncIds)) {
    echo "⚠️  No se encontraron sync_ids en los logs\n";
    echo "💡 Limpiando todo el cache...\n";
    cache()->flush();
    echo "✅ Cache limpiado completamente\n";
    exit(0);
}

echo "📌 Sync IDs encontrados: " . count($syncIds) . "\n\n";

$cleaned = 0;
foreach ($syncIds as $syncId) {
    echo "🔍 Procesando: {$syncId}\n";
    
    $status = cache()->get($syncId . '_status');
    $progress = cache()->get($syncId . '_progress');
    $pid = cache()->get($syncId . '_pid');
    
    echo "   Estado: " . ($status ?? 'N/A') . "\n";
    echo "   Progreso: " . ($progress ?? 'N/A') . "%\n";
    echo "   PID: " . ($pid ?? 'N/A') . "\n";
    
    // Verificar si el proceso existe
    $processExists = false;
    if ($pid) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>nul", $output);
            $processExists = count($output) > 3; // Si hay más de 3 líneas, el proceso existe
        } else {
            exec("ps -p {$pid} 2>/dev/null", $output, $returnCode);
            $processExists = $returnCode === 0;
        }
    }
    
    if (!$processExists && $status === 'running') {
        echo "   ⚠️  Proceso no existe pero cache dice 'running'\n";
        echo "   🧹 Limpiando cache...\n";
        
        // Limpiar todas las claves relacionadas
        $keys = [
            '_status', '_progress', '_message', '_current', '_total',
            '_processed', '_created', '_updated', '_errors',
            '_started_at', '_completed_at', '_cancelled_at', '_pid'
        ];
        
        foreach ($keys as $key) {
            cache()->forget($syncId . $key);
        }
        
        $cleaned++;
        echo "   ✅ Cache limpiado para {$syncId}\n";
    } elseif ($processExists) {
        echo "   ⚠️  Proceso aún existe (PID: {$pid})\n";
        echo "   💡 Usa: php artisan glpi:stop-sync --sync-id={$syncId}\n";
    } else {
        echo "   ✅ Ya está limpio\n";
    }
    
    echo "\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ Proceso completado\n";
echo "📊 Sincronizaciones limpiadas: {$cleaned}\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
