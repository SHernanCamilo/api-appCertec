<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = DB::select('SHOW TABLES');
echo "=== TABLAS CON 'wf_' o 'grupo' ===\n";
foreach ($tables as $t) {
    $name = array_values((array)$t)[0];
    if (str_contains($name, 'wf_') || str_contains($name, 'grupo')) {
        // Show columns
        $cols = DB::select("SHOW COLUMNS FROM `{$name}`");
        $colNames = array_map(fn($c) => $c->Field . '(' . $c->Type . ')', $cols);
        echo "\n📋 {$name}\n   " . implode(', ', $colNames) . "\n";
    }
}
