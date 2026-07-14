<?php
/**
 * Script para marcar todas las migraciones existentes como completadas
 * excepto la nueva de OData (bi_vista_user_permissions).
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$migrationsPath = database_path('migrations');
$files = array_diff(scandir($migrationsPath), ['.', '..']);
$count = 0;

foreach ($files as $file) {
    if (!str_ends_with($file, '.php')) continue;

    $name = str_replace('.php', '', $file);

    // Saltar nuestra migración nueva para que sí se ejecute
    if (str_contains($name, 'bi_vista_user_permissions')) {
        echo "SKIP (nueva): $name\n";
        continue;
    }

    // Verificar si ya está registrada
    $exists = DB::table('migrations')->where('migration', $name)->exists();
    if ($exists) {
        continue;
    }

    DB::table('migrations')->insert([
        'migration' => $name,
        'batch' => 1,
    ]);
    $count++;
    echo "REGISTERED: $name\n";
}

echo "\n=== $count migraciones marcadas como completadas ===\n";
echo "Ahora puedes ejecutar: php artisan migrate\n";
