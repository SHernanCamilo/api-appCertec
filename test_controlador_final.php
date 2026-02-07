<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== PROBANDO CONTROLADOR CORREGIDO ===" . PHP_EOL;

use App\Models\MatrizObsolescencia\MatzobsActivosC;

// Probar directamente con el modelo correcto
echo "1. PROBANDO MODELO DIRECTAMENTE:" . PHP_EOL;

try {
    $activos = MatzobsActivosC::with(['detalles', 'empresa', 'sucursal', 'sede'])
        ->orderBy('id', 'asc')
        ->limit(3)
        ->get();
    
    echo "✅ Activos obtenidos: " . $activos->count() . PHP_EOL;
    
    foreach ($activos as $activo) {
        echo PHP_EOL . "ACTIVO ID: " . $activo->id . PHP_EOL;
        echo "Nombre: " . $activo->nombre_equipo . PHP_EOL;
        echo "Agente: " . $activo->agente . PHP_EOL;
        echo "Detalles cargados: " . ($activo->detalles ? 'SÍ' : 'NO') . PHP_EOL;
        
        if ($activo->detalles) {
            echo "  Marca: " . ($activo->detalles->marca ?? 'NULL') . PHP_EOL;
            echo "  Tipo: " . ($activo->detalles->tipo ?? 'NULL') . PHP_EOL;
            echo "  RAM: " . ($activo->detalles->tamano_ram ?? 'NULL') . PHP_EOL;
            echo "  Procesador: " . ($activo->detalles->procesador ?? 'NULL') . PHP_EOL;
        }
        
        echo "Empresa: " . ($activo->empresa->nombre ?? 'NULL') . PHP_EOL;
        echo "Sucursal: " . ($activo->sucursal->nombre ?? 'NULL') . PHP_EOL;
        echo "Sede: " . ($activo->sede->nombre ?? 'NULL') . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "❌ ERROR MODELO: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
}

echo PHP_EOL . "2. PROBANDO CONTROLADOR:" . PHP_EOL;

try {
    $controller = new App\Http\Controllers\MatrizObsActivoController();
    $request = new Illuminate\Http\Request();
    $request->merge(['per_page' => 5, 'page' => 1]);

    $user = App\Models\User::find(3); // HERNAN ADMINISTRADOR
    Auth::login($user);

    echo "Usuario autenticado: " . Auth::user()->name . PHP_EOL;

    $response = $controller->getActivosPorPermisos($request);
    
    echo "Status Code: " . $response->getStatusCode() . PHP_EOL;
    
    if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getContent(), true);
        
        echo "Success: " . ($data['success'] ? 'true' : 'false') . PHP_EOL;
        echo "Total: " . ($data['total'] ?? 0) . PHP_EOL;
        echo "Registros: " . count($data['data'] ?? []) . PHP_EOL;
        
        if (!empty($data['data'])) {
            $primerActivo = $data['data'][0];
            echo PHP_EOL . "PRIMER ACTIVO DEL CONTROLADOR:" . PHP_EOL;
            echo "ID: " . $primerActivo['id'] . PHP_EOL;
            echo "Nombre: " . $primerActivo['nombre_equipo'] . PHP_EOL;
            echo "Agente: " . $primerActivo['agente'] . PHP_EOL;
            
            // Verificar estructura completa
            echo PHP_EOL . "ESTRUCTURA COMPLETA:" . PHP_EOL;
            echo "Keys disponibles: " . implode(', ', array_keys($primerActivo)) . PHP_EOL;
            
            // Verificar detalles específicamente
            if (isset($primerActivo['detalles'])) {
                echo "✅ TIENE DETALLES:" . PHP_EOL;
                echo "  Marca: " . ($primerActivo['detalles']['marca'] ?? 'NULL') . PHP_EOL;
                echo "  Tipo: " . ($primerActivo['detalles']['tipo'] ?? 'NULL') . PHP_EOL;
                echo "  RAM: " . ($primerActivo['detalles']['tamano_ram'] ?? 'NULL') . PHP_EOL;
            } else {
                echo "❌ NO TIENE DETALLES" . PHP_EOL;
            }
        }
    } else {
        echo "Error: " . $response->getContent() . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "❌ ERROR CONTROLADOR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
}