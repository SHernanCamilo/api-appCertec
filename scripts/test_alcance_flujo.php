<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$notifier = app(\App\Services\Workflow\WorkflowNotifier::class);
$uf = \App\Models\Config\ConfigUnidadFuncional::find(19);
$contexto = [
    'id_empresa' => $uf->id_empresa,
    'id_unidad_funcional' => 19,
    'id_sucursal' => $uf->id_sucursal,
    'id_sede' => $uf->id_sede,
];

$flujo = \App\Models\Workflow\WfDefinicion::where('codigo', 'EVENTOS_COMPLETO')->first();
foreach ($flujo->pasos()->ordenados()->get() as $paso) {
    $users = $notifier->resolverAprobadoresParaPaso($paso, $contexto);
    $ap = \App\Models\Workflow\WfAprobador::where('id_paso', $paso->id)->first();
    echo "{$paso->nombre_paso} (permiso={$ap->permiso_codigo}, alcance={$ap->alcance}): ";
    echo $users->pluck('name')->implode(', ') ?: '(vacío)';
    echo PHP_EOL;
}

echo "Responsables UF19: " . $notifier->obtenerResponsablesUnidadFuncional(19)->pluck('name')->implode(', ') . PHP_EOL;
