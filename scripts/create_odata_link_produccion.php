<?php
/**
 * Script para crear un link OData ORGANIZACIONAL en producción.
 * Ejecutar en la VPS después de git pull + migrate.
 *
 * Uso:
 *   /opt/cpanel/ea-php83/root/usr/bin/php scripts/create_odata_link_produccion.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\OdataLink;
use App\Models\User;

// Buscar usuario admin
$user = User::where('email', 'jscabreras@medilaser.com.co')->first();
if (!$user) {
    echo "ERROR: Usuario jscabreras@medilaser.com.co no encontrado.\n";
    exit(1);
}

echo "=== Creando links OData para producción ===\n\n";

// ─── Link 1: Inventario Productos (organizacional) ───────────────────
$code1 = OdataLink::generateCode();
OdataLink::updateOrCreate(
    ['name' => 'Excel - Inventario Productos', 'created_by' => $user->id],
    [
        'code'             => $code1,
        'visibility'       => OdataLink::VISIBILITY_ORGANIZATIONAL,
        'created_by_email' => $user->email,
        'schema_name'      => 'in',
        'view_name'        => 'VW_Inventory_Productos',
        'columns'          => null,
        'filters'          => null,
        'max_rows'         => 500000,
        'expires_at'       => now()->addMonths(6),
    ]
);

echo "1. Inventario Productos (ORGANIZACIONAL)\n";
echo "   URL: https://jade-api.medilaser.com.co/api/fabric/odata/link/{$code1}\n";
echo "   Auth: Cuenta de organización (@medilaser.com.co)\n\n";

// ─── Link 2: Gestantes Registro Tipo 5 Neiva (organizacional) ────────
$code2 = OdataLink::generateCode();
OdataLink::updateOrCreate(
    ['name' => 'Excel - Gestantes Registro Tipo5 Nva', 'created_by' => $user->id],
    [
        'code'             => $code2,
        'visibility'       => OdataLink::VISIBILITY_ORGANIZATIONAL,
        'created_by_email' => $user->email,
        'schema_name'      => 'dc',
        'view_name'        => 'VW_HC_GestantesRegistroTipo5_Nva',
        'columns'          => null,
        'filters'          => null,
        'max_rows'         => 500000,
        'expires_at'       => now()->addMonths(6),
    ]
);

echo "2. Gestantes Registro Tipo5 Nva (ORGANIZACIONAL)\n";
echo "   URL: https://jade-api.medilaser.com.co/api/fabric/odata/link/{$code2}\n";
echo "   Auth: Cuenta de organización (@medilaser.com.co)\n\n";

// ─── Link 3: Cartera ExtractoCartera (organizacional) ────────────────
$code3 = OdataLink::generateCode();
OdataLink::updateOrCreate(
    ['name' => 'Excel - Extracto Cartera', 'created_by' => $user->id],
    [
        'code'             => $code3,
        'visibility'       => OdataLink::VISIBILITY_ORGANIZATIONAL,
        'created_by_email' => $user->email,
        'schema_name'      => 'ca',
        'view_name'        => 'VW_Portfolio_ExtractoCartera',
        'columns'          => null,
        'filters'          => null,
        'max_rows'         => 1000000,
        'expires_at'       => now()->addMonths(6),
    ]
);

echo "3. Extracto Cartera (ORGANIZACIONAL)\n";
echo "   URL: https://jade-api.medilaser.com.co/api/fabric/odata/link/{$code3}\n";
echo "   Auth: Cuenta de organización (@medilaser.com.co)\n\n";

echo "=== INSTRUCCIONES ===\n\n";
echo "En Excel:\n";
echo "  1. Datos → Desde una fuente OData\n";
echo "  2. Pegar la URL de arriba\n";
echo "  3. Autenticación: 'Cuenta de organización'\n";
echo "  4. Iniciar sesión con @medilaser.com.co\n";
echo "  5. Seleccionar 'value' → Cargar\n\n";
echo "NOTA: Solo funciona con HTTPS (producción), NO con localhost.\n";
echo "Los links expiran en 6 meses.\n";
