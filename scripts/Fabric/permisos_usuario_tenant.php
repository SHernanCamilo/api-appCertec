<?php

/**
 * Grupos del usuario en el tenant (equivalente a "Grupos" en Entra ID).
 *
 * Uso:
 *   php scripts/Fabric/permisos_usuario_tenant.php usuario@empresa.com
 *   php scripts/Fabric/permisos_usuario_tenant.php usuario@empresa.com medilaser
 *   php scripts/Fabric/permisos_usuario_tenant.php usuario@empresa.com jersalud
 *
 * Muestra solo grupos cuyo nombre comienza con GG-BD (sin importar mayúsculas).
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email      = $argv[1] ?? null;
$tenantType = strtolower($argv[2] ?? 'medilaser');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Uso: php scripts/Fabric/permisos_usuario_tenant.php <email> [medilaser|jersalud]\n";
    exit(1);
}

if (!in_array($tenantType, ['medilaser', 'jersalud'], true)) {
    echo "Tenant inválido: {$tenantType}. Use medilaser o jersalud.\n";
    exit(1);
}

if ($tenantType === 'jersalud') {
    $clientId     = env('MICROSOFT_JERSALUD_CLIENT_ID') ?: config('services.microsoft.client_id');
    $clientSecret = env('MICROSOFT_JERSALUD_CLIENT_SECRET') ?: config('services.microsoft.client_secret');
    $tenantId     = env('MICROSOFT_JERSALUD_TENANT_ID');
    $tenantName   = 'Jersalud';
} else {
    $clientId     = config('services.microsoft.client_id');
    $clientSecret = config('services.microsoft.client_secret');
    $tenantId     = env('MICROSOFT_MEDILASER_TENANT_ID');
    $tenantName   = 'Medilaser';
}

if (!$clientId || !$clientSecret || !$tenantId) {
    echo "Faltan variables de entorno (MICROSOFT_CLIENT_ID/SECRET o TENANT_ID).\n";
    exit(1);
}

function graphRequest(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        return [
            'ok'    => false,
            'code'  => $httpCode,
            'error' => $data['error']['message'] ?? "HTTP {$httpCode}",
        ];
    }

    return ['ok' => true, 'data' => $data];
}

function graphGetAllPages(string $url, string $token): array
{
    $items = [];

    while ($url) {
        $result = graphRequest($url, $token);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'], 'items' => $items];
        }

        $data  = $result['data'];
        $items = array_merge($items, $data['value'] ?? []);
        $url   = $data['@odata.nextLink'] ?? null;
    }

    return ['ok' => true, 'items' => $items];
}

function decodeJwtPayload(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) < 2) {
        return [];
    }
    return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [];
}

function rutaDesdeDn(?string $dn, string $nombre): string
{
    if (!$dn) {
        return '—';
    }

    $partes = array_map('trim', explode(',', $dn));
    $dc     = [];
    $ous    = [];

    foreach ($partes as $parte) {
        if (str_starts_with($parte, 'DC=')) {
            $dc[] = substr($parte, 3);
        } elseif (str_starts_with($parte, 'OU=')) {
            $ous[] = substr($parte, 3);
        }
    }

    if (empty($dc)) {
        return $dn;
    }

    $dominio = implode('.', $dc);
    $ous     = array_reverse($ous);

    return empty($ous)
        ? "{$dominio}/{$nombre}"
        : $dominio . '/' . implode('/', $ous) . '/' . $nombre;
}

function tipoGrupo(array $g): string
{
    $tipos = $g['groupTypes'] ?? [];
    if (in_array('Unified', $tipos, true)) {
        return 'Microsoft 365';
    }
    if ($g['securityEnabled'] ?? false) {
        return 'Seguridad';
    }
    return 'Distribución';
}

function origenGrupo(array $g): string
{
    if (!empty($g['onPremisesSamAccountName']) || !empty($g['onPremisesDistinguishedName'])) {
        return 'Windows Server AD';
    }
    return 'Nube';
}

function nombreGrupo(array $g): string
{
    return $g['displayName']
        ?? $g['mailNickname']
        ?? $g['onPremisesSamAccountName']
        ?? '';
}

function enrichGroups(array $stubs, string $token): array
{
    $groupSelect = 'displayName,mailNickname,onPremisesSamAccountName,onPremisesDistinguishedName,securityEnabled,groupTypes,mail';
    $enriched    = [];

    foreach ($stubs as $stub) {
        if (!empty($stub['displayName'])) {
            $enriched[] = $stub;
            continue;
        }

        $id     = $stub['id'] ?? null;
        if (!$id) {
            continue;
        }

        $result = graphRequest(
            "https://graph.microsoft.com/v1.0/groups/{$id}?\$select={$groupSelect}",
            $token
        );

        $enriched[] = $result['ok'] ? $result['data'] : $stub;
    }

    return $enriched;
}

// ── Token ─────────────────────────────────────────────────────────────────────
$ch = curl_init("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$tokenData = json_decode(curl_exec($ch), true);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($tokenData['access_token'])) {
    $err = $tokenData['error_description'] ?? $tokenData['error'] ?? 'Error desconocido';
    echo "No se pudo obtener token: {$err}\n";
    exit(1);
}

$accessToken = $tokenData['access_token'];
$jwtRoles    = decodeJwtPayload($accessToken)['roles'] ?? [];

// ── Buscar usuario ────────────────────────────────────────────────────────────
$userFilter = rawurlencode("mail eq '{$email}' or userPrincipalName eq '{$email}'");
$userResult = graphRequest(
    "https://graph.microsoft.com/v1.0/users?\$filter={$userFilter}&\$select=id,displayName,mail,userPrincipalName,department,jobTitle,onPremisesSamAccountName",
    $accessToken
);

if (!$userResult['ok']) {
    echo "Error al buscar usuario: {$userResult['error']}\n";
    exit(1);
}

$usuario = $userResult['data']['value'][0] ?? null;

if (!$usuario) {
    echo "Usuario no encontrado en el tenant: {$email}\n";
    exit(1);
}

$userId = $usuario['id'];

// ── Grupos ────────────────────────────────────────────────────────────────────
$memberUrl    = "https://graph.microsoft.com/v1.0/users/{$userId}/memberOf/microsoft.graph.group?\$top=999";
$gruposResult = graphGetAllPages($memberUrl, $accessToken);

echo "=== GRUPOS DEL USUARIO — TENANT {$tenantName} ===\n\n";
echo "Nombre       : {$usuario['displayName']}\n";
echo "Email        : " . ($usuario['mail'] ?? $usuario['userPrincipalName']) . "\n";
echo "UPN          : {$usuario['userPrincipalName']}\n";
echo "Departamento : " . ($usuario['department'] ?? '—') . "\n";
echo "Cargo        : " . ($usuario['jobTitle'] ?? '—') . "\n\n";

if (!$gruposResult['ok']) {
    echo "Error al listar grupos: {$gruposResult['error']}\n";
    exit(1);
}

$grupos = $gruposResult['items'];

if (in_array('Group.Read.All', $jwtRoles, true)) {
    $grupos = enrichGroups($grupos, $accessToken);
}

$totalGrupos = count($grupos);
$sinNombre     = collect($grupos)->filter(fn ($g) => nombreGrupo($g) === '')->count();

if ($sinNombre > 0 && !in_array('Group.Read.All', $jwtRoles, true)) {
    echo "Se encontraron {$totalGrupos} grupos en el tenant, pero no se pueden leer los nombres.\n";
    echo "Agregue Group.Read.All (tipo Aplicación) y vuelva a ejecutar el script.\n\n";
    exit(0);
}

$miembros = collect($grupos)
    ->map(function ($g) {
        $nombre = nombreGrupo($g) ?: ($g['id'] ?? '(sin nombre)');
        return [
            'nombre' => $nombre,
            'tipo'   => tipoGrupo($g),
            'origen' => origenGrupo($g),
            'ruta'   => rutaDesdeDn($g['onPremisesDistinguishedName'] ?? null, $nombre),
        ];
    })
    ->filter(fn ($g) => str_starts_with(strtoupper($g['nombre']), 'GG-BD'))
    ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
    ->values();

echo "Grupos GG-BD ({$miembros->count()}):\n";
echo str_repeat('-', 90) . "\n";
printf("%-30s %-18s %-20s %s\n", 'Nombre', 'Tipo de grupo', 'Origen', 'Ubicación AD');
echo str_repeat('-', 90) . "\n";

foreach ($miembros as $g) {
    printf("%-30s %-18s %-20s %s\n", $g['nombre'], $g['tipo'], $g['origen'], $g['ruta']);
}

echo "\n";
