<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Ver si hay relación empresa-módulo para BI-ODATA-LINKS (id=66)
echo "=== Empresas con acceso a BI-ODATA-LINKS (id=66) ===\n";
$empresas = DB::table('seg_modulo_empresa')->where('id_modulo', 66)->get();
if ($empresas->isEmpty()) {
    echo "  NINGUNA empresa tiene acceso.\n";
} else {
    foreach ($empresas as $e) {
        echo "  id_empresa={$e->id_empresa}\n";
    }
}

// Ver empresas que tienen acceso a Esquemas BI (id=65) para comparar
echo "\n=== Empresas con acceso a BI-PARAMETROS-ESQ (id=65) ===\n";
$empresasEsq = DB::table('seg_modulo_empresa')->where('id_modulo', 65)->get();
if ($empresasEsq->isEmpty()) {
    echo "  NINGUNA empresa tiene acceso.\n";
} else {
    foreach ($empresasEsq as $e) {
        echo "  id_empresa={$e->id_empresa}\n";
    }
}

// Columnas de seg_modulo_empresa
echo "\n=== Columnas seg_modulo_empresa ===\n";
$cols = DB::select('SHOW COLUMNS FROM seg_modulo_empresa');
foreach ($cols as $c) {
    echo "  {$c->Field} => {$c->Type}\n";
}

// El usuario actual (id=17 o el que use)
echo "\n=== Tu usuario (id=17) en seg_empresa_user ===\n";
$eu = DB::table('seg_empresa_user')->where('user_id', 17)->get();
foreach ($eu as $e) {
    echo "  empresa_id={$e->empresa_id}\n";
}
