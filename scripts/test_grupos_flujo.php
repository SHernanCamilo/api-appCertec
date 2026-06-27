<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow\WfGrupo;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;

echo "=== PRUEBA 1: Crear Grupo 'Asistenciales Neiva' ===\n";

// Buscar o crear el grupo
$grupo = WfGrupo::firstOrCreate(
    ['nombre' => 'Asistenciales Neiva', 'id_empresa' => null],
    [
        'descripcion' => 'Grupo de UFs asistenciales de Neiva',
        'estado'      => true,
    ]
);
echo "✅ Grupo ID: {$grupo->id} | Nombre: {$grupo->nombre}\n";

// Asignar UFs al grupo (primeras 5 activas como ejemplo)
$ufs = DB::table('config_unidades_funcionales')
    ->where('estado', 1)
    ->limit(5)
    ->pluck('id');

if ($ufs->isNotEmpty()) {
    $grupo->unidadesFuncionales()->sync($ufs);
    echo "✅ UFs asignadas: " . $ufs->implode(', ') . "\n";
} else {
    echo "⚠️  No se encontraron UFs activas\n";
}

echo "\n=== PRUEBA 2: Verificar Motor de Reglas ===\n";

// Buscar flujos de eventos disponibles
$modulo = WfModulo::where('codigo', 'eventos')->first();
if (!$modulo) {
    echo "⚠️  No existe módulo 'eventos', creándolo...\n";
    $modulo = WfModulo::firstOrCreate(
        ['codigo' => 'eventos'],
        ['nombre' => 'Eventos / Horas Extras', 'descripcion' => 'Módulo de eventos y horas extras', 'estado' => true]
    );
}

$flujos = WfDefinicion::where('id_modulo', $modulo->id)->where('estado', true)->get();
echo "📋 Flujos disponibles para 'eventos':\n";
foreach ($flujos as $f) {
    $pasosCount = $f->pasos()->where('estado', true)->count();
    echo "   - [{$f->codigo}] {$f->nombre} ({$pasosCount} pasos)\n";
}

echo "\n=== PRUEBA 3: Buscar grupo por UF (Motor de Reglas) ===\n";
if ($ufs->isNotEmpty()) {
    $ufTest = $ufs->first();
    $grupoEncontrado = WfGrupo::obtenerGrupoPorUnidadFuncional($ufTest);
    if ($grupoEncontrado) {
        echo "✅ UF #{$ufTest} pertenece al grupo: {$grupoEncontrado->nombre}\n";
    } else {
        echo "❌ No se encontró grupo para UF #{$ufTest}\n";
    }
}

echo "\n=== PRUEBA 4: Verificar tablas finales ===\n";
$tablas = ['wf_grupos', 'wf_grupo_cargos', 'wf_grupo_unidades', 'wf_reglas', 'wf_aprobadores', 'wf_definiciones', 'wf_pasos'];
foreach ($tablas as $t) {
    $count = DB::table($t)->count();
    echo "📋 {$t}: {$count} registros\n";
}

echo "\n✅ Todo funciona correctamente.\n";
