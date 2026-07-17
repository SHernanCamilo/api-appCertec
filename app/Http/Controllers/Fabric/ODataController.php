<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\OdataAccessLog;
use App\Models\OdataLink;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        // Log dedicado para OData (canal separado para no perder entre otros logs)
        $odataLog = Log::channel('odata');
        $odataLog->info('OData request', [
            'code' => $code,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
        ]);

        // Restringir acceso solo a clientes Office/Power Query
        $userAgent = $request->userAgent() ?? '';
        $allowedClients = ['Microsoft.Data.Mashup', 'PowerQuery', 'Excel', 'Power BI', 'OData'];
        $isOfficeClient = false;
        foreach ($allowedClients as $client) {
            if (stripos($userAgent, $client) !== false) {
                $isOfficeClient = true;
                break;
            }
        }
        // Restricción estricta: solo clientes de Office/Power Query
        if (!$isOfficeClient) {
            $odataLog->warning('OData: Cliente no permitido', ['user_agent' => $userAgent, 'code' => $code]);
            return $this->odataError('ClientNotAllowed', 'Por motivos de seguridad, este endpoint solo está disponible desde Microsoft Excel o Power Query.', 403);
        }

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
            // Si requiere autenticación, devolver 401 con header WWW-Authenticate
            // Power Query usa este header para iniciar flujo OAuth ("Cuenta de organización")
            if ($authResult['code'] === 'AuthRequired') {
                $tenantId = env('MICROSOFT_MEDILASER_TENANT_ID', 'common');
                return response()->json([
                    'error' => ['code' => 'AuthRequired', 'message' => $authResult['message']],
                ], 401)->withHeaders([
                    'WWW-Authenticate' => 'Basic realm="JadeOne OData - Use su email y API Key"',
                ]);
            }
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

        $top = min((int) $request->query('$top', '20000'), 20000);
        $skip = max((int) $request->query('$skip', '0'), 0);
        $filter = $request->query('$filter', '');
        $select = $request->query('$select', '');
        $orderby = $request->query('$orderby', '');

        // Protección robusta contra SQL y JS injection en filtros OData
        if ($filter && preg_match('/;|--|DROP|DELETE|INSERT|UPDATE|EXEC|xp_|UNION|SCRIPT|ALTER|CREATE|TRUNCATE|\/\*|<script>/i', $filter)) {
            return $this->odataError('InvalidFilter', 'Por motivos de seguridad, el filtro OData contiene caracteres o sentencias no permitidas.', 400);
        }

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

        // Cache OData: misma consulta (link + offset + filtros) → respuesta cacheada 2 min
        $odataCacheKey = 'odata_qry:' . md5("{$code}:{$skip}:{$top}:" . json_encode($filters));
        $odataCacheTtl = 120; // 2 minutos

        $result = \Illuminate\Support\Facades\Cache::remember($odataCacheKey, $odataCacheTtl, function () use ($link, $columns, $filters, $top, $skip, $sortCol, $sortDir) {
            return $this->gateway->queryAsSystem(
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
        });

        $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            // No cachear errores
            \Illuminate\Support\Facades\Cache::forget($odataCacheKey);
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

        // Agregar __id como Key a cada fila (Excel OData lo requiere)
        $indexedItems = [];
        $baseId = $skip; // Para que sea único entre páginas
        foreach ($items as $i => $item) {
            $item['__id'] = $baseId + $i + 1;
            $indexedItems[] = $item;
        }

        // Construir respuesta OData
        $response = [
            '@odata.context' => url("/api/fabric/odata/link/{$code}/\$metadata#value"),
            'value' => $indexedItems,
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
        $odataLog = Log::channel('odata');
        $odataLog->info('OData $metadata request', ['code' => $code, 'ip' => $request->ip()]);

        $link = OdataLink::where('code', $code)->first();

        if (!$link) {
            return response('Not found', 404);
        }

        // Obtener columnas reales de la vista desde Graph-Fabric (cache 5 min)
        $cacheKey = "odata_metadata:{$link->schema_name}:{$link->view_name}";
        $columns = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($link) {
            $result = $this->gateway->getViewColumns(
                \App\Models\User::find($link->created_by),
                $link->schema_name,
                $link->view_name
            );
            return $result['success'] ? ($result['data']['columns'] ?? []) : [];
        });

        // Generar propiedades EDMX dinámicamente
        $properties = '        <Property Name="__id" Type="Edm.Int32" Nullable="false"/>' . "\n";
        foreach ($columns as $col) {
            $name = $col['name'] ?? '';
            if ($name === '' || $name === '__id') continue;
            $edmType = $this->sqlTypeToEdm($col['type'] ?? 'varchar');
            $properties .= "        <Property Name=\"{$name}\" Type=\"{$edmType}\" Nullable=\"true\"/>\n";
        }

        $edmx = '<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">
  <edmx:DataServices>
    <Schema Namespace="Fabric" xmlns="http://docs.oasis-open.org/odata/ns/edm">
      <EntityType Name="Row">
        <Key><PropertyRef Name="__id"/></Key>
' . $properties . '      </EntityType>
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

    /**
     * Mapear tipo SQL de Fabric a tipo EDM de OData.
     */
    private function sqlTypeToEdm(string $sqlType): string
    {
        return match (strtolower($sqlType)) {
            'int', 'bigint', 'smallint', 'tinyint' => 'Edm.Int64',
            'decimal', 'numeric', 'money', 'smallmoney', 'float', 'real' => 'Edm.Decimal',
            'bit' => 'Edm.Boolean',
            // Fechas como String para evitar errores de formato (Fabric no envía timezone)
            'date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset' => 'Edm.String',
            default => 'Edm.String',
        };
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
                'url' => url("/api/fabric/odata/link/{$link->code}"),
                'excel_url' => url("/api/fabric/odata/link/{$link->code}"),
                'expires_at' => $link->expires_at?->toIso8601String(),
            ],
        ];

        // El token público solo se muestra UNA VEZ al crear
        if ($tokenData) {
            $response['data']['public_token'] = $tokenData['token'];
            $response['data']['full_url'] = url("/api/fabric/odata/link/{$link->code}") . "?token={$tokenData['token']}";
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
                'url' => url("/api/fabric/odata/link/{$l->code}"),
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
    // API KEYS — Generar/Listar/Revocar
    // =========================================================================

    /**
     * Generar API Key personal para el usuario autenticado.
     * POST /api/fabric/odata/api-keys
     */
    public function generateApiKey(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'expires_days' => 'nullable|integer|min:1|max:365',
            'scope' => 'nullable|in:private,shared',
        ]);

        $user = auth()->user();
        $scope = $request->input('scope', 'private');
        $keyData = \App\Models\OdataApiKey::generateKey();

        $apiKey = \App\Models\OdataApiKey::create([
            'user_id'    => $user->id,
            'name'       => $request->name,
            'key_hash'   => $keyData['hash'],
            'key_prefix' => $keyData['prefix'],
            'scope'      => $scope,
            'expires_at' => $request->expires_days
                ? now()->addDays($request->expires_days)
                : null,
        ]);

        $instructions = $scope === 'shared'
            ? 'Key compartida: cualquier usuario con permiso Excel puede usarla con su propio correo + esta key.'
            : 'En Excel → Fuente OData → Básico → Usuario: ' . $user->email . ' → Contraseña: (pegar la key)';

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $apiKey->id,
                'name'       => $apiKey->name,
                'key'        => $keyData['key'], // ⚠️ Solo se muestra UNA VEZ
                'prefix'     => $keyData['prefix'],
                'scope'      => $scope,
                'expires_at' => $apiKey->expires_at?->toIso8601String(),
                'instructions' => $instructions,
            ],
            'warning' => 'Guarda esta key. No se puede recuperar después.',
        ], 201);
    }

    /**
     * Listar API Keys del usuario autenticado.
     * GET /api/fabric/odata/api-keys
     */
    public function listApiKeys(): JsonResponse
    {
        $keys = \App\Models\OdataApiKey::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'key_prefix', 'scope', 'active', 'expires_at', 'last_used_at', 'use_count', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => $keys,
        ]);
    }

    /**
     * Revocar una API Key.
     * DELETE /api/fabric/odata/api-keys/{id}
     */
    public function revokeApiKey(int $id): JsonResponse
    {
        $key = \App\Models\OdataApiKey::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$key) {
            return response()->json(['success' => false, 'message' => 'Key no encontrada.'], 404);
        }

        $key->update(['active' => false]);

        return response()->json(['success' => true, 'message' => 'API Key revocada.']);
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

        // Niveles PRIVATE y ORGANIZATIONAL: requieren autenticación
        $user = auth('api')->user();

        if (!$user) {
            // Intentar Basic Auth (email + API Key personal o compartida de JadeOne)
            $basicAuth = $request->header('Authorization', '');
            if (str_starts_with($basicAuth, 'Basic ')) {
                $credentials = base64_decode(substr($basicAuth, 6));
                $parts = explode(':', $credentials, 2);
                if (count($parts) === 2) {
                    $email = $parts[0];
                    $apiKey = $parts[1];

                    // Validar API Key contra la BD (soporta private y shared)
                    $keyRecord = \App\Models\OdataApiKey::validateKey($email, $apiKey);
                    if ($keyRecord) {
                        // Para keys SHARED, el usuario real es quien envía el email (no el dueño de la key)
                        // Para keys PRIVATE, el usuario es el dueño de la key
                        if ($keyRecord->isShared()) {
                            // Key compartida: buscar el usuario real por email
                            $realUser = \App\Models\User::where('email', strtolower($email))->first();
                            if (!$realUser) {
                                return [
                                    'error' => true,
                                    'code' => 'UserNotFound',
                                    'message' => "El usuario '{$email}' no existe en el sistema.",
                                ];
                            }
                            $keyUser = $realUser;
                        } else {
                            // Key privada: el usuario es el dueño de la key
                            $keyUser = $keyRecord->user;
                        }

                        // Verificar permisos del usuario para este link/esquema
                        if (!$link->canAccess($keyUser->email, $request->ip())) {
                            return [
                                'error' => true,
                                'code' => 'AccessDenied',
                                'message' => 'No tiene permiso para acceder a este link.',
                            ];
                        }

                        // Verificar que el usuario tiene acceso al esquema de la vista
                        if (!$this->gateway->tieneAccesoEsquema($keyUser, $link->schema_name)) {
                            return [
                                'error' => true,
                                'code' => 'SchemaAccessDenied',
                                'message' => "Su cuenta no tiene acceso al esquema '{$link->schema_name}'.",
                            ];
                        }

                        // Verificar permisos específicos de OData para esta vista
                        if (!$this->checkViewPermission($keyUser, $link)) {
                            return [
                                'error' => true,
                                'code' => 'ViewAccessDenied',
                                'message' => "No tiene permiso asignado para actualizar la vista '{$link->view_name}' desde Excel.",
                            ];
                        }

                        // Registrar uso
                        $keyRecord->recordUse($request->ip());

                        Log::info('OData API Key: autenticación exitosa', [
                            'email' => $keyUser->email,
                            'key_prefix' => $keyRecord->key_prefix,
                            'scope' => $keyRecord->scope,
                        ]);

                        return [
                            'error' => false,
                            'email' => $keyUser->email,
                            'name' => $keyUser->name ?? $keyUser->email,
                            'method' => $keyRecord->isShared() ? 'api_key_shared' : 'api_key',
                        ];
                    } else {
                        Log::warning('OData API Key: key inválida', ['email' => $email]);
                    }
                }
            }

            // Intentar Bearer token de Azure AD directo (para Power Query avanzado)
            $bearerToken = $request->bearerToken();
            if ($bearerToken) {
                $azureUser = $this->validateAzureToken($bearerToken);
                if ($azureUser) {
                    if (!$link->canAccess($azureUser['email'], $request->ip())) {
                        return [
                            'error' => true,
                            'code' => 'AccessDenied',
                            'message' => 'No tiene permiso para acceder a este link.',
                        ];
                    }
                    $azureUserModel = \App\Models\User::where('email', $azureUser['email'])->first();
                    if (!$azureUserModel) {
                        return [
                            'error' => true,
                            'code' => 'UserNotFound',
                            'message' => 'Usuario no encontrado en la base de datos.',
                        ];
                    }
                    if (!$this->checkViewPermission($azureUserModel, $link)) {
                        return [
                            'error' => true,
                            'code' => 'ViewAccessDenied',
                            'message' => "No tiene permiso asignado para actualizar la vista '{$link->view_name}' desde Excel.",
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
                'message' => 'Autenticación requerida. Use su correo @medilaser.com.co y contraseña.',
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

        if (!$this->checkViewPermission($user, $link)) {
            return [
                'error' => true,
                'code' => 'ViewAccessDenied',
                'message' => "No tiene permiso asignado para actualizar la vista '{$link->view_name}' desde Excel.",
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
     * Verifica si el usuario tiene permiso explícito para esta vista
     */
    private function checkViewPermission($user, OdataLink $link): bool
    {
        // Si el usuario es admin puede acceder a todo
        if ($user && method_exists($user, 'hasRole') && $user->hasRole(['admin', 'super-admin'])) {
            return true;
        }

        $biGrupo = \App\Models\BiGrupo::where('codigo', strtoupper($link->schema_name))->first();
        if (!$biGrupo) {
            return false;
        }

        $biVista = \App\Models\BiVista::where('id_bi_grupos', $biGrupo->id)
            ->where('nombre', $link->view_name)
            ->first();

        if (!$biVista) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('bi_vista_user_permissions')
            ->where('bi_vista_id', $biVista->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Validar credenciales contra la BD local de Laravel.
     * No pasa por Azure AD — evita problemas de Conditional Access/MFA/Intune.
     * Solo acepta @medilaser.com.co y verifica password con bcrypt.
     */
    private function validateBasicLocal(string $email, string $password): ?array
    {
        // Solo aceptar emails corporativos
        if (!str_ends_with(strtolower($email), '@medilaser.com.co')) {
            return null;
        }

        // Buscar usuario en la BD
        $user = \App\Models\User::where('email', strtolower($email))->first();
        if (!$user) {
            Log::debug('OData Basic Local: usuario no encontrado', ['email' => $email]);
            return null;
        }

        // Verificar contraseña (bcrypt)
        if (!\Illuminate\Support\Facades\Hash::check($password, $user->password)) {
            Log::warning('OData Basic Local: contraseña incorrecta', ['email' => $email]);
            return null;
        }

        Log::info('OData Basic Local: autenticación exitosa', ['email' => $email, 'user_id' => $user->id]);

        return [
            'email' => $user->email,
            'name'  => $user->name ?? $user->email,
        ];
    }

    /**
     * Validar credenciales contra Azure AD usando ROPC (fallback).
     * NOTA: No funciona si el tenant tiene Conditional Access con device compliance.
     */
    private function validateBasicWithAzure(string $email, string $password): ?array
    {
        // Solo aceptar emails de Medilaser
        if (!str_ends_with(strtolower($email), '@medilaser.com.co')) {
            return null;
        }

        try {
            $tenantId = env('MICROSOFT_MEDILASER_TENANT_ID', 'common');
            $clientId = env('MICROSOFT_CLIENT_ID', '');
            $clientSecret = env('MICROSOFT_CLIENT_SECRET', '');

            // ROPC flow: validar credenciales contra Azure AD
            // User-Agent de Windows para que Azure identifique la plataforma correctamente
            // Sin esto, Azure clasifica como "Unknown" y Conditional Access bloquea (AADSTS50005)
            $response = Http::asForm()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ])
                ->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type'    => 'password',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'username'      => $email,
                    'password'      => $password,
                    'scope'         => 'openid profile email',
                ]
            );

            if ($response->failed()) {
                Log::warning('OData Basic Auth: Azure AD RECHAZÓ', [
                    'email'  => $email,
                    'status' => $response->status(),
                    'error'  => $response->json('error') ?? 'unknown',
                    'error_description' => $response->json('error_description') ?? substr($response->body(), 0, 300),
                ]);
                return null;
            }

            $data = $response->json();
            $idToken = $data['id_token'] ?? null;

            // Decodificar el id_token para obtener nombre
            if ($idToken) {
                $parts = explode('.', $idToken);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                    return [
                        'email' => $email,
                        'name'  => $payload['name'] ?? $email,
                    ];
                }
            }

            return [
                'email' => $email,
                'name'  => $email,
            ];
        } catch (\Exception $e) {
            Log::debug('OData Basic Auth: error validando', ['error' => $e->getMessage()]);
            return null;
        }
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

            // Verificar audience (debe ser nuestra app, api://CLIENT_ID o el dominio)
            $clientId = env('MICROSOFT_CLIENT_ID', '');
            $aud = $payload['aud'] ?? '';
            $validAudiences = [
                $clientId,
                "api://{$clientId}",
                'https://jade-api.medilaser.com.co',
            ];
            // Aceptar cualquiera de los audiences válidos
            if ($clientId && !in_array($aud, $validAudiences, true)) {
                Log::debug('OData: Audience no reconocido', ['aud' => $aud, 'valid' => $validAudiences]);
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