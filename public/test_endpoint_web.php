<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\MatrizObsActivoC;
use App\Models\User;

try {
    // Simular autenticación
    $user = User::first();
    if (!$user) {
        echo json_encode(['error' => 'No hay usuarios']);
        exit;
    }
    
    auth()->login($user);
    
    // Consultar activos directamente
    $activos = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede'])
        ->take(10)
        ->get();
    
    $response = [
        'success' => true,
        'message' => 'Test directo del endpoint',
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'empresas_count' => $user->empresas()->count()
        ],
        'data' => $activos->map(function($activo) {
            return [
                'id' => $activo->id,
                'nombre_equipo' => $activo->nombre_equipo,
                'agente' => $activo->agente,
                'placa' => $activo->placa,
                'serial' => $activo->serial,
                'puntaje' => $activo->puntaje,
                'empresa' => $activo->empresa ? [
                    'id' => $activo->empresa->id,
                    'nombre' => $activo->empresa->nombre
                ] : null,
                'sucursal' => $activo->sucursal ? [
                    'id' => $activo->sucursal->id,
                    'nombre' => $activo->sucursal->nombre
                ] : null,
                'sede' => $activo->sede ? [
                    'id' => $activo->sede->id,
                    'nombre' => $activo->sede->nombre
                ] : null,
                'detalle' => $activo->detalle ? [
                    'marca' => $activo->detalle->marca,
                    'tipo' => $activo->detalle->tipo,
                    'referencia' => $activo->detalle->referencia
                ] : null
            ];
        }),
        'total' => MatrizObsActivoC::count(),
        'timestamp' => now()->toISOString()
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>