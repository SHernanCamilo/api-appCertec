<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TalentoHumano\EventHoraExtra;
use App\Models\User;
use App\Models\Config\ConfigUnidadFuncional;
use App\Services\Workflow\WorkflowNotifier;

$consecutivo = $argv[1] ?? null;

$query = EventHoraExtra::with(['empleado', 'novedad', 'instancia.definicion', 'instancia.pasoActual', 'aprobador'])
    ->orderByDesc('id');

if ($consecutivo) {
    $query->where('consecutivo', $consecutivo);
}

$ev = $query->first();

if (!$ev) {
    echo "No se encontró evento\n";
    exit(1);
}

$uf = ConfigUnidadFuncional::find($ev->id_unidad_funcional);
$reg = User::find($ev->id_user_reg);

echo "=== EVENTO ===\n";
echo json_encode([
    'consecutivo' => $ev->consecutivo,
    'empleado' => $ev->empleado?->nombre,
    'novedad' => ($ev->novedad?->codigo ?? '') . ' - ' . ($ev->novedad?->descripcion ?? ''),
    'unidad_funcional' => $uf ? ($uf->codigo . ' - ' . $uf->nombre) : $ev->id_unidad_funcional,
    'estado' => $ev->estado,
    'registrador' => $reg?->name,
    'wf_instancia_id' => $ev->wf_instancia_id,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (!$ev->wf_instancia_id) {
    echo "\n⚠️  El evento NO tiene flujo wf_instancia asociado (quedó solo Registrado).\n";
    exit(0);
}

$inst = $ev->instancia;
$notifier = app(WorkflowNotifier::class);

echo "\n=== FLUJO ASIGNADO ===\n";
echo json_encode([
    'codigo' => $inst->definicion?->codigo,
    'nombre' => $inst->definicion?->nombre,
    'paso_actual' => $inst->pasoActual?->nombre_paso,
    'estado_instancia' => $inst->estado,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== PASOS Y QUIÉN INTERVIENE ===\n";
$pasos = $inst->definicion?->pasos()->activos()->ordenados()->with('aprobadores')->get() ?? collect();

foreach ($pasos as $paso) {
    $marca = ($inst->pasoActual && $inst->pasoActual->id === $paso->id) ? ' ← ACTUAL' : '';
    echo "\n{$paso->orden}. {$paso->nombre_paso}{$marca}\n";

    foreach ($paso->aprobadores as $ap) {
        $tipo = $ap->permiso_codigo
            ? "permiso: {$ap->permiso_codigo}"
            : ($ap->id_user ? "usuario fijo: {$ap->id_user}" : 'sin config');
        echo "   Config: {$tipo}\n";
    }

    // Simular instancia en este paso para resolver nombres
    $instSim = clone $inst;
    $instSim->setRelation('pasoActual', $paso);
    $instSim->id_paso_actual = $paso->id;

    $users = $notifier->resolverAprobadores($instSim);
    if ($users->isEmpty()) {
        echo "   Intervinientes: (ninguno resuelto — revisar permisos/config)\n";
    } else {
        foreach ($users as $u) {
            echo "   - {$u->name} ({$u->email})\n";
        }
    }
}

echo "\n=== QUIEN DEBE ACTUAR AHORA ===\n";
$actuales = $notifier->resolverAprobadores($inst);
if ($actuales->isEmpty()) {
    echo "Nadie resuelto para el paso actual. Verifique permisos apro-evento/auto-evento/digi-evento.\n";
} else {
    foreach ($actuales as $u) {
        echo "- {$u->name} ({$u->email})\n";
    }
}
