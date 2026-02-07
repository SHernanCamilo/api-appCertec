<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== PROBANDO RELACIONES EN CONTROLADOR ===" . PHP_EOL;

// Probar directamente con el modelo correcto
use App\Models\MatrizObsolescencia\MatzobsActivosC;

$controller = new App\Http\Controllers\MatrizObsActivoController();
$request = new Illuminate\Http\Request();
$request->merge(['per_page' => 5, 'page' => 1]);

$user = App\Models\User::find(3); // HERNAN ADMINISTRADOR
Auth::login($user);

echo "Usuario: " . Auth::user()->name . PHP_EOL;

try {
    $response = $controller->getActivosPorPermisos($request);
    
    echo "Status Code: " . $response->getStatusCode() . PHP_EOL;
    
    if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getContent(), true);
        
        echo "Success: " . ($data['success'] ? 'true' : 'false') . PHP_EOL;
        echo "Total: " . ($data['total'] ?? 0) . PHP_EOL;
        echo "Registros: " . count($data['data'] ?? []) . PHP_EOL;
        
        if (!empty($data['data'])) {
            $primerActivo = $data['data'][0];
            echo PHP_EOL . "PRIMER ACTIVO:" . PHP_EOL;
            echo "ID: " . $primerActivo['id'] . PHP_EOL;
            echo "Nombre: " . $primerActivo['nombre_equipo'] . PHP_EOL;
            echo "Agente: " . $primerActivo['agente'] . PHP_EOL;
            
            // Verificar si tiene detalles
            if (isset($primerActivo['detalles'])) {
                echo "✅ TIENE DETALLES:" . PHP_EOL;
                echo "  Marca: " . ($primerActivo['detalles']['marca'] ?? 'NULL') . PHP_EOL;
                echo "  Tipo: " . ($primerActivo['detalles']['tipo'] ?? 'NULL') . PHP_EOL;
                echo "  Referencia: " . ($primerActivo['detalles']['referencia'] ?? 'NULL') . PHP_EOL;
                echo "  RAM: " . ($primerActivo['detalles']['tamano_ram'] ?? 'NULL') . PHP_EOL;
                echo "  Procesador: " . ($primerActivo['detalles']['procesador'] ?? 'NULL') . PHP_EOL;
                echo "  Disco: " . ($primerActivo['detalles']['tamano_disco'] ?? 'NULL') . PHP_EOL;
                echo "  Interfaz: " . ($primerActivo['detalles']['interfaz_conexion'] ?? 'NULL') . PHP_EOL;
            } else {
                echo "❌ NO TIENE DETALLES" . PHP_EOL;
            }
            
            // Verificar empresa, sucursal, sede
            echo PHP_EOL . "RELACIONES ORGANIZACIONALES:" . PHP_EOL;
            echo "Empresa: " . ($primerActivo['empresa']['nombre'] ?? 'NULL') . PHP_EOL;
            echo "Sucursal: " . ($primerActivo['sucursal']['nombre'] ?? 'NULL') . PHP_EOL;
            echo "Sede: " . ($primerActivo['sede']['nombre'] ?? 'NULL') . PHP_EOL;
        }
    } else {
        echo "Error: " . $response->getContent() . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
}

echo PHP_EOL . "=== PROBANDO DIRECTAMENTE CON MODELO ===" . PHP_EOL;

// Probar directamente con el modelo
try {
    $activos = MatzobsActivosC::with(['detalles', 'empresa', 'sucursal', 'sede'])
        ->orderBy('id', 'asc')
        ->limit(3)
        ->get();
    
    echo "Activos obtenidos directamente: " . $activos->count() . PHP_EOL;
    
    foreach ($activos as $activo) {
        echo PHP_EOL . "ACTIVO ID: " . $activo->id . PHP_EOL;
        echo "Nombre: " . $activo->nombre_equipo . PHP_EOL;
        echo "Detalles: " . ($activo->detalles ? 'SÍ' : 'NO') . PHP_EOL;
        
        if ($activo->detalles) {
            echo "  Marca: " . ($activo->detalles->marca ?? 'NULL') . PHP_EOL;
            echo "  Tipo: " . ($activo->detalles->tipo ?? 'NULL') . PHP_EOL;
        }
    }
    
} catch (Exception $e) {
    echo "ERROR MODELO: " . $e->getMessage() . PHP_EOL;
}