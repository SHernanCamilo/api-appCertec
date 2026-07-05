<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::find(3);
$sidebar = app(\App\Services\SidebarService::class)->getSidebarModules($user);

function findMod(array $mods, string $code): ?array {
    foreach ($mods as $m) {
        if (($m['codigo'] ?? '') === $code) {
            return $m;
        }
        if (!empty($m['hijos'])) {
            $f = findMod($m['hijos'], $code);
            if ($f) {
                return $f;
            }
        }
    }
    return null;
}

$m = findMod($sidebar, 'BI-VISTAS');
echo "BI-VISTAS hijos:\n";
foreach ($m['hijos'] ?? [] as $h) {
    echo "  {$h['codigo']}: {$h['ruta']}\n";
}
