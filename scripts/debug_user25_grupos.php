<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::find(25);
$gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

$grupos = \App\Models\UserGrup::where('id_user', 25)
    ->where('tipo', 'vista_bd')
    ->pluck('permiso')
    ->filter(fn ($g) => str_starts_with(strtoupper($g), 'GG-BD-'))
    ->values()
    ->all();

$catalogo = $gateway->getCatalogoGrupos();

echo "User groups: " . count($grupos) . PHP_EOL;
foreach ([1, 2, 3, null] as $tipo) {
    $label = $tipo === null ? 'ALL' : "tipo $tipo";
    $filtered = $gateway->getGruposBd($user, $tipo);
    echo "$label: " . count($filtered) . " -> " . implode(', ', $filtered) . PHP_EOL;
}

echo PHP_EOL . "Lookup failures:" . PHP_EOL;
foreach ($grupos as $g) {
    $key = strtoupper($g);
    $found = isset($catalogo[$key]);
    $short = 'GG-BD-' . strtoupper(explode('-', $key)[2] ?? '');
    $shortKey = str_replace('GG-BD-', '', $key);
    $foundShort = isset($catalogo[$shortKey]);
    if (!$found) {
        echo "  $g -> catalog[$key]=" . ($found ? 'yes' : 'no') . " catalog[$shortKey]=" . ($foundShort ? 'yes tipo='.$catalogo[$shortKey]['tipo'] : 'no') . PHP_EOL;
    }
}
