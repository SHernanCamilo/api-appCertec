<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\OdataAccessLog;
use App\Models\OdataLink;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controlador OData para conexión directa desde Excel/Power Query.
 *
 * Soporta 3 niveles de seguridad:
 *   - PRIVATE:        Solo el usuario creador (Azure AD + user match)
 *   - ORGANIZATIONAL: Cualquier @medilaser.com.co (Azure AD)
 *   - PUBLIC:         Token firmado en URL (para auditorias/dashboards temporales)
 *
 * Excel se conecta con "Cuenta de organización" (Azure AD) o URL con token.
 * Power Query sigue @odata.nextLink automáticamente para paginar.
 */
class ODataController extends Controller
{
    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {
    }

    // =========================================================================
    // ENDPOINT PRINCIPAL: GET /odata/link/{code}
    // =========================================================================

    /**
     * Acceder a un link OData — siempre devuelve UNA página (5K max).
     * Power Query sigue @odata.nextLink automáticamente para cargar todo.
     *
     * Excel "Fuente OData" llama primero la URL raíz esperando un service document.
     * Si no hay $top/$skip → devolver service document.
     * Si hay $top/$skip o el Accept indica datos → devolver datos.
     */
    public function queryByLink(Request $request, string $code): mixed
    {
        $link = OdataLink::where('code', $code)->first();

        if (!$link) {
            return $this->odataError('LinkNotFound', 'El link no existe o fue eliminado.', 404);
        }

        if (!$link->isValid()) {
            return $this->odataError('LinkExpired', 'El link ha expirado o fue desactivado.', 403);
        }

        // Autenticar
        $authResult = $this->authenticateRequest($request, $link);
        if ($authResult['error']) {
            return $this->odataError($authResult['code'], $authResult['message'], 401);
        }

        // Si Excel pide el service document (primera vez, sin params de datos)
        // Si la URL termina en /value → siempre devolver datos (Excel agrega /value al EntitySet)
        $isValueEndpoint = str_ends_with($request->path(), '/value');
        $hasDataParams = $request->has('$top') || $request->has('$skip') || $request->has('$filter');
        if (!$hasDataParams && !$isValueEndpoint) {
            return $this->serviceDocument($code, $link);
        }

        // Devolver datos paginados
        return $this->fetchData($request, $code, $link, $authResult);
    }

    /**
     * Service Document OData — Excel lo necesita para reconocer la fuente.
     * Lista las "entidades" disponibles (en nuestro caso, solo "value" = la vista).
     */
    private function serviceDocument(string $code, OdataLink $link): JsonResponse
    {
        $baseUrl = url("/api/fabric/odata/link/{$code}");

        return response()->json([
            '@odata.context' => "{$baseUrl}/\$metadata",
            'value' => [
                [
                    'name' => 'value',
                    'kind' => 'EntitySet',
                    'url'  => 'value',
                ],
            ],
        ], 200, [
            'OData-Version' => '4.0',
            'Content-Type'  => 'application/json; odata.metadata=minimal',
        ]);
    }

    /**
     * Datos paginados con formato OData.
     * Power Query sigue @odata.nextLink para cargar todas las páginas.
     */
    private function fetchData(Request $request, string $code, OdataLink $link, array $authResult): JsonResponse
    {
        $userEmail = $authResult['email'];
        $userName = $authResult['name'];

        $top = min((int) $request->query('$top', '5000'), 5000);
        $skip = max((int) $request->query('$skip', '0'), 0);
        $filter = $request->query('$filter', '');
        $select = $request->query('$select', '');
        $orderby = $request->query('$orderby', '');

        $filters = array_merge(
            $link->filters ?? [],
            $this->parseODataFilter($filter)
        );

        $columns = $select
            ? array_map('trim', explode(',', $select))
            : ($link->columns ?? []);

        [$sortCol, $sortDir] = $this->parseOrderBy($orderby);
        if (!$sortCol) {
            $sortCol = $link->sort_col ?? '';
            $sortDir = $link->sort_dir ?? 'asc';
        }

        $startTime = microtime(true);

        $result = $this->gateway->queryAsSystem(
            $link->schema_name,
            $link->view_name,
            [
                'columns' => $columns,
                'filters' => $filters,
                'limit' => $top,
                'offset' => $skip,
                'sort_col' => $sortCol,
                'sort_dir' => $sortDir,
            ]
        );

        $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            return $this->odataError('DataSourceError', $result['message'] ?? 'Error consultando datos.', 502);
        }

        $items = $result['data'] ?? [];
        $hasNext = $result['meta']['has_next'] ?? (count($items) === $top);

        // Registrar acceso
        $link->recordAccess();
        OdataAccessLog::create([
            'odata_link_id' => $link->id,
            'user_email' => $userEmail,
            'user_name' => $userName,
            'schema_name' => $link->schema_name,
            'view_name' => $link->view_name,
            'visibility' => $link->visibility,
            'filter_applied' => $filter ?: null,
            'top' => $top,
            'skip' => $skip,
            'rows_returned' => count($items),
            'elapsed_ms' => $elapsedMs,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'auth_method' => $authResult['method'],
        ]);

        // Construir respuesta OData (sin @odata.count — Excel no lo acepta en entity sets)
        $response = [
            '@odata.context' => url("/api/fabric/odata/link/{$code}/\$metadata#value"),
            'value' => $items,
        ];

        // SIEMPRE incluir nextLink si hay más páginas — Power Query lo sigue automáticamente
        if ($hasNext && count($items) > 0) {
            $nextSkip = $skip + $top;
            $nextParams = array_filter([
                '$top' => (string) $top,
                '$skip' => (string) $nextSkip,
                '$filter' => $filter ?: null,
                '$select' => $select ?: null,
                '$orderby' => $orderby ?: null,
                'token' => $request->query('token'),
            ]);
            $response['@odata.nextLink'] = url("/api/fabric/odata/link/{$code}/value")
                . '?' . http_build_query($nextParams);
        }

        return response()->json($response, 200, [
            'OData-Version' => '4.0',
            'Content-Type' => 'application/json; odata.metadata=minimal',
        ]);
    }

    // =========================================================================
    // METADATA — Service Document para que Excel reconozca la fuente OData
    // =========================================================================

    /**
     * GET /api/fabric/odata/link/{code}/$metadata
     * Devuelve un EDMX mínimo que Excel necesita para reconocer la fuente.
     */
    public function metadata(Request $request, string $code)
    {
        $link = OdataLink::where('code', $code)->first();

        if (!$link) {
            return response('Not found', 404);
        }

        // EDMX mínimo — Excel solo necesita saber que es un servicio OData válido
        $edmx = '<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">
  <edmx:DataServices>
    <Schema Namespace="Fabric" xmlns="http://docs.oasis-open.org/odata/ns/edm">
      <EntityType Name="Row">
        <Key><PropertyRef Name="Id"/></Key>
        <Property Name="Id" Type="Edm.Int32" Nullable="false"/>
      </EntityType>
      <EntityContainer Name="Container">
        <EntitySet Name="value" EntityType="Fabric.Row"/>
      </EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>';

        return response($edmx, 200, [
            'Content-Type' => 'application/xml',
            'OData-Version' => '4.0',
        ]);
    }

    // =========================================================================
    // CRUD DE LINKS (para admin/usuarios autenticados)
    // =========================================================================

    /**
     * Crear un nuevo link OData.
     * POST /api/fabric/odata/links
     */
    public function createLink(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:150',
            'visibility' => 'required|in:private,organizational,public',
            'schema_name' => 'required|string|max:20',
            'view_name' => 'required|string|max:150',
            'columns' => 'nullable|array',
            'filters' => 'nullable|array',
            'sort_col' => 'nullable|string|max:100',
            'sort_dir' => 'nullable|in:asc,desc',
            'max_rows' => 'nullable|integer|min:100|max:1000000',
            'expires_at' => 'nullable|date|after:now',
            'allowed_ips' => 'nullable|array',
            'allowed_users' => 'nullable|array',
        ]);

        $user = auth()->user();

        // Validar que el usuario tiene acceso al esquema
        if (!$this->gateway->tieneAccesoEsquema($user, $request->schema_name)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso al esquema '{$request->schema_name}'.",
            ], 403);
        }

        $code = OdataLink::generateCode();
        $tokenData = null;

        // Para links públicos, generar token firmado
        if ($request->visibility === 'public') {
            $tokenData = OdataLink::generatePublicToken();
        }

        $link = OdataLink::create([
            'code' => $code,
            'name' => $request->name,
            'visibility' => $request->visibility,
            'created_by' => $user->id,
            'created_by_email' => $user->email,
            'schema_name' => strtolower($request->schema_name),
            'view_name' => $request->view_name,
            'columns' => $request->columns,
            'filters' => $request->filters,
            'sort_col' => $request->sort_col,
            'sort_dir' => $request->sort_dir ?? 'asc',
            'max_rows' => $request->max_rows ?? 100000,
            'token_hash' => $tokenData['hash'] ?? null,
            'expires_at' => $request->expires_at,
            'allowed_ips' => $request->allowed_ips,
            'allowed_users' => $request->allowed_users,
        ]);

        $response = [
            'success' => true,
            'data' => [
                'id' => $link->id,
                'code' => $link->code,
                'name' => $link->name,
                'visibility' => $link->visibility,
                'url' => url("/odata/link/{$link->code}"),
                'excel_url' => url("/odata/link/{$link->code}"),
                'expires_at' => $link->expires_at?->toIso8601String(),
            ],
        ];

        // El token público solo se muestra UNA VEZ al crear
        if ($tokenData) {
            $response['data']['public_token'] = $tokenData['token'];
            $response['data']['full_url'] = url("/odata/link/{$link->code}") . "?token={$tokenData['token']}";
            $response['data']['warning'] = 'Guarda este token. No se puede recuperar después.';
        }

        return response()->json($response, 201);
    }

    /**
     * Listar links del usuario autenticado.
     * GET /api/fabric/odata/links
     */
    public function listLinks(Request $request): JsonResponse
    {
        $user = auth()->user();
        $links = OdataLink::where('created_by', $user->id)
            ->orderByDesc('created_at')
            ->get([
                'id',
                'code',
                'name',
                'visibility',
                'schema_name',
                'view_name',
                'active',
                'expires_at',
                'access_count',
                'last_accessed_at',
                'created_at'
            ]);

        return response()->json([
            'success' => true,
            'data' => $links->map(fn($l) => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'visibility' => $l->visibility,
                'schema' => $l->schema_name,
                'view' => $l->view_name,
                'url' => url("/odata/link/{$l->code}"),
                'active' => $l->active,
                'expires_at' => $l->expires_at?->toIso8601String(),
                'access_count' => $l->access_count,
                'last_accessed_at' => $l->last_accessed_at?->toIso8601String(),
                'created_at' => $l->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Desactivar un link.
     * DELETE /api/fabric/odata/links/{id}
     */
    public function deactivateLink(int $id): JsonResponse
    {
        $link = OdataLink::where('id', $id)
            ->where('created_by', auth()->id())
            ->first();

        if (!$link) {
            return response()->json(['success' => false, 'message' => 'Link no encontrado.'], 404);
        }

        $link->update(['active' => false]);

        return response()->json(['success' => true, 'message' => 'Link desactivado.']);
    }

    // =========================================================================
    // AUTENTICACIÓN
    // =========================================================================

    /**
     * Autenticar el request según el nivel de visibilidad del link.
     */
    private function authenticateRequest(Request $request, OdataLink $link): array
    {
        // Nivel PÚBLICO: aceptar token en query param
        if ($link->visibility === OdataLink::VISIBILITY_PUBLIC) {
            $token = $request->query('token', '');
            if (!$token || !$link->validatePublicToken($token)) {
                return [
                    'error' => true,
                    'code' => 'InvalidToken',
                    'message' => 'Token inválido o faltante para link público.',
                ];
            }
            return [
                'error' => false,
                'email' => 'public_access',
                'name' => 'Acceso Público',
                'method' => 'token_public',
            ];
        }

        // Niveles PRIVATE y ORGANIZATIONAL: requieren Azure AD (auth:api de Laravel)
        $user = auth('api')->user();

        if (!$user) {
            // Intentar Bearer token de Azure AD directo (para Excel)
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                $azureUser = $this->validateAzureToken($bearerToken);
                if ($azureUser) {
                    // Verificar acceso según nivel
                    if (!$link->canAccess($azureUser['email'], $request->ip())) {
                        return [
                            'error' => true,
                            'code' => 'AccessDenied',
                            'message' => 'No tiene permiso para acceder a este link.',
                        ];
                    }
                    return [
                        'error' => false,
                        'email' => $azureUser['email'],
                        'name' => $azureUser['name'],
                        'method' => 'azure_ad',
                    ];
                }
            }

            return [
                'error' => true,
                'code' => 'AuthRequired',
                'message' => 'Autenticación requerida. Use "Cuenta de organización" en Excel.',
            ];
        }

        // Usuario autenticado con JWT de Laravel (desde Angular/Postman)
        if (!$link->canAccess($user->email, $request->ip())) {
            return [
                'error' => true,
                'code' => 'AccessDenied',
                'message' => 'No tiene permiso para acceder a este link.',
            ];
        }

        return [
            'error' => false,
            'email' => $user->email,
            'name' => $user->name ?? $user->email,
            'method' => 'azure_ad',
        ];
    }

    /**
     * Validar Bearer token de Azure AD (para cuando Excel se conecta con "Cuenta de organización").
     * Decodifica el JWT, verifica expiración y extrae email del usuario.
     * Retorna datos del usuario o null si es inválido.
     */
    private function validateAzureToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) return null;

            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!$payload) return null;

            // Verificar que no esté expirado
            $exp = $payload['exp'] ?? 0;
            if ($exp < time()) return null;

            // Verificar que sea del tenant de Medilaser
            // El issuer puede ser:
            //   https://sts.windows.net/{tenant_id}/
            //   https://login.microsoftonline.com/{tenant_id}/v2.0
            $tenantId = env('MICROSOFT_MEDILASER_TENANT_ID', env('AZURE_TENANT_ID', ''));
            $iss = $payload['iss'] ?? '';
            $tid = $payload['tid'] ?? '';

            // Validar tenant (preferir tid del payload, fallback a issuer)
            if ($tenantId) {
                $tenantMatch = ($tid === $tenantId) || str_contains($iss, $tenantId);
                if (!$tenantMatch) {
                    Log::debug('OData: Token de otro tenant', ['tid' => $tid, 'expected' => $tenantId]);
                    return null;
                }
            }

            // Verificar audience (debe ser nuestra app o api://CLIENT_ID)
            $clientId = env('MICROSOFT_CLIENT_ID', '');
            $aud = $payload['aud'] ?? '';
            if ($clientId && $aud !== $clientId && $aud !== "api://{$clientId}") {
                // Si el audience no coincide, puede ser un token de Graph — aún aceptar si es del tenant correcto
                Log::debug('OData: Audience diferente', ['aud' => $aud, 'expected' => $clientId]);
            }

            // Extraer email
            $email = $payload['preferred_username']
                ?? $payload['upn']
                ?? $payload['email']
                ?? $payload['unique_name']
                ?? '';

            if (!$email) return null;

            // Verificar que sea @medilaser.com.co
            if (!str_ends_with(strtolower($email), '@medilaser.com.co')) {
                Log::warning('OData: Token de usuario no-Medilaser', ['email' => $email]);
                return null;
            }

            return [
                'email' => $email,
                'name'  => $payload['name'] ?? $email,
            ];
        } catch (\Exception $e) {
            Log::debug('OData: Error validando token Azure', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // HELPERS OData
    // =========================================================================

    private function parseODataFilter(string $filter): array
    {
        if (empty($filter))
            return [];

        $filters = [];
        $parts = preg_split('/\s+and\s+/i', $filter);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match("/^(\w+)\s+eq\s+'([^']+)'$/i", $part, $m)) {
                $filters[$m[1]] = $m[2];
            } elseif (preg_match("/^(\w+)\s+eq\s+(\d+)$/i", $part, $m)) {
                $filters[$m[1]] = (int) $m[2];
            } elseif (preg_match("/^contains\((\w+),\s*'([^']+)'\)$/i", $part, $m)) {
                $filters[$m[1]] = "%{$m[2]}%";
            } elseif (preg_match("/^startswith\((\w+),\s*'([^']+)'\)$/i", $part, $m)) {
                $filters[$m[1]] = "{$m[2]}%";
            }
        }

        return $filters;
    }

    private function parseOrderBy(string $orderby): array
    {
        if (empty($orderby))
            return ['', 'asc'];
        $parts = explode(' ', trim($orderby));
        $col = $parts[0] ?? '';
        $dir = strtolower($parts[1] ?? 'asc');
        return [$col, in_array($dir, ['asc', 'desc']) ? $dir : 'asc'];
    }

    private function odataError(string $code, string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $httpStatus);
    }
}