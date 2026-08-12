<?php

namespace App\Services\Tenant;

use App\Models\AllowedDomain;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphTenantService
{
    public const GRUPO_VISTA_PREFIX = 'GG-BD';

    /**
     * @return array{client_id: string, client_secret: string, tenant_id: string, tenant_name: string}|null
     */
    public function resolveTenantConfig(User $user): ?array
    {
        $jersaludTenantId = config('services.microsoft.jersalud_tenant_id');

        if ($user->tenant_id && $jersaludTenantId && $user->tenant_id === $jersaludTenantId) {
            return $this->jersaludConfig();
        }

        if ($user->tenant_id && $user->tenant_id === config('services.microsoft.medilaser_tenant_id')) {
            return $this->medilaserConfig();
        }

        $allowedDomain = AllowedDomain::getByEmail($user->email);
        if ($allowedDomain?->tenant_id) {
            if ($jersaludTenantId && $allowedDomain->tenant_id === $jersaludTenantId) {
                return $this->jersaludConfig();
            }

            return [
                'client_id'     => config('services.microsoft.client_id'),
                'client_secret' => config('services.microsoft.client_secret'),
                'tenant_id'     => $allowedDomain->tenant_id,
                'tenant_name'   => $allowedDomain->tenant_name ?? 'Medilaser',
            ];
        }

        return $this->medilaserConfig();
    }

    /**
     * @return array{
     *   success: bool,
     *   department: ?string,
     *   job_title: ?string,
     *   grupos_vista_bd: array<int, string>,
     *   member_of_count: int,
     *   error: ?string
     * }
     */
    public function fetchUserTenantData(User $user): array
    {
        $config = $this->resolveTenantConfig($user);

        if (!$config || !$config['client_id'] || !$config['client_secret'] || !$config['tenant_id']) {
            return $this->emptyResult('Tenant Microsoft no configurado');
        }

        try {
            $token = $this->getAccessToken(
                $config['tenant_id'],
                $config['client_id'],
                $config['client_secret']
            );

            $graphUser = $this->findUserByEmail($user->email, $token);
            if (!$graphUser) {
                return $this->emptyResult('Usuario no encontrado en el tenant de Microsoft');
            }

            $groupsResult = $this->fetchGgBdGroups($graphUser['id'], $token);

            return [
                'success'          => true,
                'department'       => $graphUser['department'] ?? null,
                'job_title'        => $graphUser['jobTitle'] ?? null,
                'grupos_vista_bd'  => $groupsResult['grupos'],
                'member_of_count'  => $groupsResult['member_of_count'],
                'error'            => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('MicrosoftGraphTenantService: error consultando Graph', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'error'   => $e->getMessage(),
            ]);

            return $this->emptyResult($e->getMessage());
        }
    }

    private function medilaserConfig(): ?array
    {
        $clientId     = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');
        $tenantId     = config('services.microsoft.medilaser_tenant_id');

        if (!$clientId || !$clientSecret || !$tenantId) {
            return null;
        }

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'tenant_id'     => $tenantId,
            'tenant_name'   => 'Medilaser',
        ];
    }

    private function jersaludConfig(): ?array
    {
        $clientId     = config('services.microsoft.jersalud_client_id') ?: config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.jersalud_client_secret') ?: config('services.microsoft.client_secret');
        $tenantId     = config('services.microsoft.jersalud_tenant_id');

        if (!$clientId || !$clientSecret || !$tenantId) {
            return null;
        }

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'tenant_id'     => $tenantId,
            'tenant_name'   => 'Jersalud',
        ];
    }

    private function getAccessToken(string $tenantId, string $clientId, string $clientSecret): string
    {
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

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode === 0) {
            throw new \RuntimeException('No se pudo obtener token Graph: ' . ($curlErr ?: 'fallo de red/timeout'));
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'Error desconocido';
            throw new \RuntimeException("No se pudo obtener token Graph: {$err}");
        }

        return $data['access_token'];
    }

    private function findUserByEmail(string $email, string $token): ?array
    {
        $filter = rawurlencode("mail eq '{$email}' or userPrincipalName eq '{$email}'");
        $result = $this->graphGet(
            "https://graph.microsoft.com/v1.0/users?\$filter={$filter}&\$select=id,department,jobTitle",
            $token
        );

        return $result['value'][0] ?? null;
    }

    /**
     * @return array{grupos: array<int, string>, member_of_count: int}
     */
    private function fetchGgBdGroups(string $graphUserId, string $token): array
    {
        $url   = "https://graph.microsoft.com/v1.0/users/{$graphUserId}/memberOf/microsoft.graph.group?\$top=999";
        $items = $this->graphGetAllPages($url, $token);

        // Si Graph devolvió membresías sin nombre, hay que enriquecerlas.
        // Sin eso el filtro GG-BD-* queda vacío y el sync borraría permisos reales.
        if (!empty($items) && $this->groupsNeedEnrichment($items)) {
            $roles = $this->decodeJwtRoles($token);
            if (!in_array('Group.Read.All', $roles, true) && !in_array('Directory.Read.All', $roles, true)) {
                throw new \RuntimeException(
                    'Graph devolvió grupos sin displayName y el token no tiene Group.Read.All/Directory.Read.All'
                );
            }

            $items = $this->enrichGroups($items, $token);

            if ($this->groupsNeedEnrichment($items)) {
                throw new \RuntimeException(
                    'No se pudo resolver el displayName de uno o más grupos Azure; sync abortado'
                );
            }
        }

        $grupos = collect($items)
            ->map(fn ($g) => $this->groupName($g))
            ->filter(fn ($name) => $name !== '' && str_starts_with(strtoupper($name), self::GRUPO_VISTA_PREFIX))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'grupos'          => $grupos,
            'member_of_count' => count($items),
        ];
    }

    private function groupsNeedEnrichment(array $groups): bool
    {
        foreach ($groups as $group) {
            if ($this->groupName($group) === '') {
                return true;
            }
        }

        return false;
    }

    private function groupName(array $group): string
    {
        return $group['displayName']
            ?? $group['mailNickname']
            ?? $group['onPremisesSamAccountName']
            ?? '';
    }

    private function enrichGroups(array $stubs, string $token): array
    {
        $select = 'displayName,mailNickname,onPremisesSamAccountName';

        return array_map(function ($stub) use ($token, $select) {
            if ($this->groupName($stub) !== '') {
                return $stub;
            }

            $id = $stub['id'] ?? null;
            if (!$id) {
                throw new \RuntimeException('Grupo Azure sin id ni displayName; sync abortado');
            }

            $result = $this->graphGet(
                "https://graph.microsoft.com/v1.0/groups/{$id}?\$select={$select}",
                $token
            );

            if ($this->groupName($result) === '') {
                throw new \RuntimeException("No se pudo enriquecer el grupo Azure {$id}");
            }

            return $result;
        }, $stubs);
    }

    private function graphGetAllPages(string $url, string $token): array
    {
        $items = [];

        while ($url) {
            $data = $this->graphGet($url, $token);

            $items = array_merge($items, $data['value'] ?? []);
            $url   = $data['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function graphGet(string $url, string $token): array
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
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Antes: curl false / HTTP 0 devolvía null y el sync seguía como "éxito" vacío,
        // borrando permisos GG-BD-* existentes. Ahora falla explícitamente.
        if ($response === false || $httpCode === 0) {
            throw new \RuntimeException('Error Graph (red/timeout): ' . ($curlErr ?: 'sin respuesta'));
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = is_array($data) ? ($data['error']['message'] ?? "HTTP {$httpCode}") : "HTTP {$httpCode}";
            throw new \RuntimeException("Error Graph: {$msg}");
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Error Graph: respuesta JSON inválida');
        }

        return $data;
    }

    private function decodeJwtRoles(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return [];
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return $payload['roles'] ?? [];
    }

    private function emptyResult(string $error): array
    {
        return [
            'success'         => false,
            'department'      => null,
            'job_title'       => null,
            'grupos_vista_bd' => [],
            'member_of_count' => 0,
            'error'           => $error,
        ];
    }
}
