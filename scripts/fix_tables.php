<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Drop the partially created wf_grupos_uf (no sirve, reutilizamos wf_grupos)
DB::statement('DROP TABLE IF EXISTS wf_grupo_uf_unidades');
DB::statement('DROP TABLE IF EXISTS wf_grupos_uf');
echo "✅ Eliminada tabla wf_grupos_uf (parcial, innecesaria)\n";

// 2. Check if id_unidad_funcional exists on humtal_ct_grupos (rollback la quitó)
$cols = collect(DB::select('SHOW COLUMNS FROM humtal_ct_grupos'))->pluck('Field');
if (!$cols->contains('id_unidad_funcional')) {
    DB::statement('ALTER TABLE humtal_ct_grupos ADD COLUMN id_unidad_funcional bigint(20) unsigned NULL AFTER id_sede');
    echo "✅ Restaurada columna id_unidad_funcional en humtal_ct_grupos\n";
} else {
    echo "ℹ️  id_unidad_funcional ya existe en humtal_ct_grupos\n";
}

// 3. Create pivot wf_grupo_unidades (vincula wf_grupos con config_unidades_funcionales)
$exists = DB::select("SHOW TABLES LIKE 'wf_grupo_unidades'");
if (empty($exists)) {
    DB::statement("
        CREATE TABLE wf_grupo_unidades (
            id_grupo BIGINT UNSIGNED NOT NULL,
            id_unidad_funcional BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id_grupo, id_unidad_funcional),
            FOREIGN KEY (id_grupo) REFERENCES wf_grupos(id) ON DELETE CASCADE,
            FOREIGN KEY (id_unidad_funcional) REFERENCES config_unidades_funcionales(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Creada tabla pivot wf_grupo_unidades (wf_grupos ↔ config_unidades_funcionales)\n";
} else {
    echo "ℹ️  wf_grupo_unidades ya existe\n";
}

// 4. Eliminar columna codigo de wf_grupos (identificación por nombre)
$colsGrupos = collect(DB::select('SHOW COLUMNS FROM wf_grupos'))->pluck('Field');
if ($colsGrupos->contains('codigo')) {
    DB::statement('ALTER TABLE wf_grupos DROP INDEX wf_grupos_codigo_unique');
    DB::statement('ALTER TABLE wf_grupos DROP COLUMN codigo');
    echo "✅ Eliminada columna codigo de wf_grupos\n";
} else {
    echo "ℹ️  wf_grupos.codigo ya fue eliminada\n";
}

$idx = collect(DB::select("SHOW INDEX FROM wf_grupos WHERE Key_name = 'wf_grupos_empresa_nombre_unique'"));
if ($idx->isEmpty()) {
    DB::statement('ALTER TABLE wf_grupos ADD UNIQUE KEY wf_grupos_empresa_nombre_unique (id_empresa, nombre)');
    echo "✅ Índice único (id_empresa, nombre) en wf_grupos\n";
} else {
    echo "ℹ️  Índice wf_grupos_empresa_nombre_unique ya existe\n";
}

echo "\n=== Estado final de las tablas ===\n";
$wfTables = ['wf_grupos', 'wf_grupo_cargos', 'wf_grupo_unidades', 'humtal_ct_grupos'];
foreach ($wfTables as $tbl) {
    $cols = DB::select("SHOW COLUMNS FROM `{$tbl}`");
    $colNames = array_map(fn($c) => $c->Field, $cols);
    echo "📋 {$tbl}: " . implode(', ', $colNames) . "\n";
}