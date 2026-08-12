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
     * @return array{success: bool, department: ?string, job_title: ?string, grupos_vista_bd: array<int, string>, error: ?string}
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

            $groups = $this->fetchGgBdGroups($graphUser['id'], $token);

            return [
                'success'          => true,
                'department'       => $graphUser['department'] ?? null,
                'job_title'        => $graphUser['jobTitle'] ?? null,
                'grupos_vista_bd'  => $groups,
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
     * @return array<int, string>
     */
    private function fetchGgBdGroups(string $graphUserId, string $token): array
    {
        $url   = "https://graph.microsoft.com/v1.0/users/{$graphUserId}/memberOf/microsoft.graph.group?\$top=999";
        $items = $this->graphGetAllPages($url, $token);

        $roles = $this->decodeJwtRoles($token);
        if (!empty($items) && $this->groupsNeedEnrichment($items) && in_array('Group.Read.All', $roles, true)) {
            $items = $this->enrichGroups($items, $token);
        }

        return collect($items)
            ->map(fn ($g) => $this->groupName($g))
            ->filter(fn ($name) => $name !== '' && str_starts_with(strtoupper($name), self::GRUPO_VISTA_PREFIX))
            ->unique()
            ->sort()
            ->values()
            ->all();
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
                return $stub;
            }

            $result = $this->graphGet(
                "https://graph.microsoft.com/v1.0/groups/{$id}?\$select={$select}",
                $token
            );

            return $result ?: $stub;
        }, $stubs);
    }

    private function graphGetAllPages(string $url, string $token): array
    {
        $items = [];

        while ($url) {
            $data = $this->graphGet($url, $token);
            if ($data === null) {
                break;
            }

            $items = array_merge($items, $data['value'] ?? []);
            $url   = $data['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    private function graphGet(string $url, string $token): ?array
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

        if ($httpCode >= 400) {
            $data = json_decode($response, true);
            $msg  = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Error Graph: {$msg}");
        }

        return json_decode($response, true);
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
            'error'           => $error,
        ];
    }
}
