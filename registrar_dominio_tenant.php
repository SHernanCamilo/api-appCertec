<?php

/**
 * Script helper para registrar dominios de tenants en allowed_domains
 * 
 * Uso:
 * php registrar_dominio_tenant.php
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     REGISTRAR DOMINIO DE TENANT PARA AZURE AD MULTI-TENANT    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Mostrar dominios actuales
echo "📋 Dominios actualmente registrados:\n";
echo "─────────────────────────────────────────────────────────────────\n";

$dominiosActuales = DB::table('allowed_domains')->get();

if ($dominiosActuales->isEmpty()) {
    echo "   ⚠️  No hay dominios registrados\n";
} else {
    foreach ($dominiosActuales as $dominio) {
        $estado = $dominio->activo ? '✅ Activo' : '❌ Inactivo';
        echo "   • {$dominio->domain}\n";
        echo "     Tenant: {$dominio->tenant_name} ({$dominio->tenant_id})\n";
        echo "     Estado: {$estado}\n";
        echo "     Empresa ID: {$dominio->id_empresa}\n";
        echo "\n";
    }
}

echo "─────────────────────────────────────────────────────────────────\n";
echo "\n";

// Solicitar datos del nuevo dominio
echo "🔧 Registrar nuevo dominio:\n";
echo "\n";

echo "Dominio (ej: @empresa.com o @empresa.onmicrosoft.com): ";
$domain = trim(fgets(STDIN));

if (empty($domain)) {
    echo "❌ Error: El dominio no puede estar vacío\n";
    exit(1);
}

// Asegurar que empiece con @
if (!str_starts_with($domain, '@')) {
    $domain = '@' . $domain;
}

// Verificar si ya existe
$existe = DB::table('allowed_domains')->where('domain', $domain)->first();
if ($existe) {
    echo "⚠️  El dominio {$domain} ya está registrado\n";
    echo "¿Deseas actualizarlo? (s/n): ";
    $actualizar = trim(fgets(STDIN));
    
    if (strtolower($actualizar) !== 's') {
        echo "❌ Operación cancelada\n";
        exit(0);
    }
    
    $esActualizacion = true;
} else {
    $esActualizacion = false;
}

echo "Tenant ID (UUID del tenant en Azure AD): ";
$tenantId = trim(fgets(STDIN));

if (empty($tenantId)) {
    echo "❌ Error: El Tenant ID no puede estar vacío\n";
    exit(1);
}

echo "Nombre del Tenant (ej: Empresa Principal): ";
$tenantName = trim(fgets(STDIN));

if (empty($tenantName)) {
    echo "❌ Error: El nombre del tenant no puede estar vacío\n";
    exit(1);
}

echo "ID de Empresa (número): ";
$idEmpresa = trim(fgets(STDIN));

if (empty($idEmpresa) || !is_numeric($idEmpresa)) {
    echo "❌ Error: El ID de empresa debe ser un número\n";
    exit(1);
}

// Verificar que la empresa existe
$empresa = DB::table('ent_empresas')->where('id', $idEmpresa)->first();
if (!$empresa) {
    echo "⚠️  Advertencia: No se encontró la empresa con ID {$idEmpresa}\n";
    echo "¿Deseas continuar de todas formas? (s/n): ";
    $continuar = trim(fgets(STDIN));
    
    if (strtolower($continuar) !== 's') {
        echo "❌ Operación cancelada\n";
        exit(0);
    }
}

echo "Descripción (opcional): ";
$descripcion = trim(fgets(STDIN));

echo "¿Activar dominio? (s/n) [s]: ";
$activoInput = trim(fgets(STDIN));
$activo = empty($activoInput) || strtolower($activoInput) === 's' ? 1 : 0;

// Confirmar datos
echo "\n";
echo "─────────────────────────────────────────────────────────────────\n";
echo "📝 Resumen de datos:\n";
echo "─────────────────────────────────────────────────────────────────\n";
echo "   Dominio:      {$domain}\n";
echo "   Tenant ID:    {$tenantId}\n";
echo "   Tenant Name:  {$tenantName}\n";
echo "   Empresa ID:   {$idEmpresa}" . ($empresa ? " ({$empresa->nombre})" : "") . "\n";
echo "   Descripción:  " . ($descripcion ?: '(ninguna)') . "\n";
echo "   Estado:       " . ($activo ? '✅ Activo' : '❌ Inactivo') . "\n";
echo "─────────────────────────────────────────────────────────────────\n";
echo "\n";

echo "¿Confirmar " . ($esActualizacion ? 'actualización' : 'registro') . "? (s/n): ";
$confirmar = trim(fgets(STDIN));

if (strtolower($confirmar) !== 's') {
    echo "❌ Operación cancelada\n";
    exit(0);
}

// Insertar o actualizar
try {
    $datos = [
        'domain' => $domain,
        'tenant_id' => $tenantId,
        'tenant_name' => $tenantName,
        'id_empresa' => $idEmpresa,
        'activo' => $activo,
        'descripcion' => $descripcion,
        'updated_at' => now()
    ];

    if ($esActualizacion) {
        DB::table('allowed_domains')
            ->where('domain', $domain)
            ->update($datos);
        
        echo "\n✅ Dominio actualizado exitosamente\n";
    } else {
        $datos['created_at'] = now();
        
        DB::table('allowed_domains')->insert($datos);
        
        echo "\n✅ Dominio registrado exitosamente\n";
    }

    echo "\n";
    echo "🎉 Los usuarios con email {$domain} ahora pueden iniciar sesión\n";
    echo "\n";
    echo "⚠️  IMPORTANTE:\n";
    echo "   • Los usuarios deben estar pre-registrados en la tabla 'users'\n";
    echo "   • Crea los usuarios desde el panel de administración antes de que intenten iniciar sesión\n";
    echo "\n";

} catch (\Exception $e) {
    echo "\n❌ Error al registrar el dominio:\n";
    echo "   {$e->getMessage()}\n";
    exit(1);
}

// Mostrar dominios actualizados
echo "─────────────────────────────────────────────────────────────────\n";
echo "📋 Dominios registrados (actualizado):\n";
echo "─────────────────────────────────────────────────────────────────\n";

$dominiosActualizados = DB::table('allowed_domains')->get();

foreach ($dominiosActualizados as $dominio) {
    $estado = $dominio->activo ? '✅' : '❌';
    echo "   {$estado} {$dominio->domain} → {$dominio->tenant_name}\n";
}

echo "─────────────────────────────────────────────────────────────────\n";
echo "\n";
