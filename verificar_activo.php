<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== VERIFICANDO ACTIVO ID=1 EN BD ===" . PHP_EOL;

// Consultar tabla cabecera
$activoC = DB::table('matzobs_activos_c')->where('id', 1)->first();
if ($activoC) {
    echo "TABLA CABECERA (matzobs_activos_c):" . PHP_EOL;
    echo "ID: " . $activoC->id . PHP_EOL;
    echo "ID GLPI: " . ($activoC->id_activo_glpi ?? 'NULL') . PHP_EOL;
    echo "Nombre: " . ($activoC->nombre_equipo ?? 'NULL') . PHP_EOL;
    echo "Agente: " . ($activoC->agente ?? 'NULL') . PHP_EOL;
    echo "Empresa: " . ($activoC->id_empresa ?? 'NULL') . PHP_EOL;
    echo "Sucursal: " . ($activoC->id_sucursal ?? 'NULL') . PHP_EOL;
    echo "Sede: " . ($activoC->id_sede ?? 'NULL') . PHP_EOL;
    echo "Placa: " . ($activoC->placa ?? 'NULL') . PHP_EOL;
    echo "Serial: " . ($activoC->serial ?? 'NULL') . PHP_EOL;
    echo "Puntaje: " . ($activoC->puntaje ?? 'NULL') . PHP_EOL;
    echo "Última sync: " . ($activoC->date_u_sincronizacion ?? 'NULL') . PHP_EOL;
    echo PHP_EOL;
    
    // Consultar tabla detalle
    $activoD = DB::table('matzobs_activos_d')->where('activo_c_id', 1)->first();
    if ($activoD) {
        echo "TABLA DETALLE (matzobs_activos_d):" . PHP_EOL;
        echo "Marca: " . ($activoD->marca ?? 'NULL') . PHP_EOL;
        echo "Tipo: " . ($activoD->tipo ?? 'NULL') . PHP_EOL;
        echo "Referencia: " . ($activoD->referencia ?? 'NULL') . PHP_EOL;
        echo "RAM: " . ($activoD->tamano_ram ?? 'NULL') . PHP_EOL;
        echo "Generación RAM: " . ($activoD->generacion_ram ?? 'NULL') . PHP_EOL;
        echo "Procesador: " . ($activoD->procesador ?? 'NULL') . PHP_EOL;
        echo "Tipo Disco: " . ($activoD->tipo_disco ?? 'NULL') . PHP_EOL;
        echo "Tamaño Disco: " . ($activoD->tamano_disco ?? 'NULL') . PHP_EOL;
        echo "Interfaz: " . ($activoD->interfaz_conexion ?? 'NULL') . PHP_EOL;
    } else {
        echo "NO HAY REGISTRO EN TABLA DETALLE" . PHP_EOL;
    }
} else {
    echo "NO SE ENCONTRÓ EL ACTIVO ID=1 EN LA TABLA CABECERA" . PHP_EOL;
}

echo PHP_EOL . "=== VERIFICANDO PERMISOS DE USUARIO ===" . PHP_EOL;

// Verificar si hay usuarios con permisos para ver este activo
$usuarios = DB::table('users')->get();
foreach ($usuarios as $usuario) {
    echo "Usuario: " . $usuario->name . " (ID: " . $usuario->id . ")" . PHP_EOL;
    
    // Verificar empresas del usuario
    $empresas = DB::table('seg_empresa_user')->where('user_id', $usuario->id)->get();
    if ($empresas->count() > 0) {
        echo "  Empresas asignadas: " . $empresas->count() . PHP_EOL;
        foreach ($empresas as $empresa) {
            echo "    - Empresa ID: " . $empresa->empresa_id . 
                 ", Sucursal: " . ($empresa->id_sucursal ?? 'NULL') . 
                 ", Sede: " . ($empresa->id_sede ?? 'NULL') . 
                 ", Recursivo: " . ($empresa->recursivo ? 'Sí' : 'No') . PHP_EOL;
        }
    } else {
        echo "  Sin empresas asignadas (acceso total)" . PHP_EOL;
    }
    echo PHP_EOL;
}