<?php
/**
 * Generar API Key para jscabreras@medilaser.com.co (test).
 * En producción, el usuario lo hace desde la web de JadeOne.
 *
 * Uso en VPS:
 *   /opt/cpanel/ea-php83/root/usr/bin/php scripts/generate_api_key_test.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\OdataApiKey;
use App\Models\User;

$user = User::where('email', 'jscabreras@medilaser.com.co')->first();
if (!$user) {
    echo "ERROR: Usuario no encontrado\n";
    exit(1);
}

$keyData = OdataApiKey::generateKey();

$apiKey = OdataApiKey::create([
    'user_id'    => $user->id,
    'name'       => 'Excel - Test OData',
    'key_hash'   => $keyData['hash'],
    'key_prefix' => $keyData['prefix'],
    'expires_at' => now()->addMonths(6),
]);

echo "=== API KEY GENERADA ===\n\n";
echo "Usuario:    {$user->email}\n";
echo "Nombre:     {$apiKey->name}\n";
echo "Expira:     {$apiKey->expires_at}\n\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  API KEY (guardar — solo se muestra una vez):                   ║\n";
echo "║  {$keyData['key']}\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";
echo "=== PARA EXCEL ===\n\n";
echo "1. Datos → Desde una fuente OData\n";
echo "2. URL: https://jade-api.medilaser.com.co/api/fabric/odata/link/TU_CODE_AQUI\n";
echo "3. Autenticación: 'Básico'\n";
echo "4. Nombre de usuario: {$user->email}\n";
echo "5. Contraseña: {$keyData['key']}\n";
echo "6. Conectar\n";
