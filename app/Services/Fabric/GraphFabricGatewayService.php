<?php

namespace App\Services\Fabric;

use App\Models\User;
use App\Models\UserGrup;
use App\Models\BiGrupo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Gateway genérico hacia la API Python Graph-Fabric.
 *
 * Responsabilidades:
 *   - Leer los grupos GG-BD-* del usuario desde users_grups
 *   - Leer el departamento del usuario desde users_grups
 *   - Actuar como proxy seguro: validar que el usuario tiene acceso
 *     al esquema solicitado antes de reenviar la solicitud a la API Py
 *   - Reenviar solicitudes a la API Python con TOKEN_ADMIN + contexto del usuario
 */
class GraphFabricGatewayService
{
    private string $baseUrl;
    private string $tokenAdmin;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl    = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $this->tokenAdmin = env('TOKEN_ADMIN', '');
        $this->timeout    = (int) env('GRAPHQL_TIMEOUT', 15);
    }

    // =========================================================================
    // HELPERS DE USUARIO
    // =========================================================================

    /**
     * Retorna los grupos GG-BD-* activos del usuario desde users_grups.
     * Ej: ["GG-BD-IN", "GG-BD-CO"]
     *
     * @return string[]
     */
    public function getGruposBd(User $user): array
    {
        return UserGrup::where('id_user', $user->id)
            ->where('tipo', UserGrup::TIPO_VISTA_BD)
            ->pluck('permiso')
            ->filter(fn ($g) => str_starts_with(strtoupper($g), 'GG-BD-'))
            ->values()
            ->all();
    }

    /**
     * Retorna el departamento del usuario (ej: "MA-TIC", "FLA-ADM").
     */
    public function getDepartamento(User $user): ?string
    {
        return UserGrup::where('id_user', $user->id)
            ->where('tipo', UserGrup::TIPO_DEPARTAMENTO)
            ->value('permiso');
    }

    /**
     * Extrae el esquema de un grupo GG-BD-{ESQUEMA}.
     * Ej: "GG-BD-IN" → "in", "GG-BD-CO" → "co"
     */
    public function extractSchema(string $grupo): string
    {
        $parts = explode('-', strtoupper($grupo));
        // GG-BD-{SCHEMA} → partes [0]=GG [1]=BD [2]=SCHEMA
        $schema = $parts[2] ?? '';
        return strtolower($schema);
    }

    /**
     * Retorna los esquemas permitidos para el usuario.
     * Ej: ["in", "co", "df"]
     *
     * @return string[]
     */
    public function getEsquemasPermitidos(User $user): array
    {
        return collect($this->getGruposBd($user))
            ->map(fn ($g) => $this->extractSchema($g))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Esquemas permitidos del usuario con nombre desde bi_grupos.
     *
     * @return array<int, array{schema: string, codigo: string, nombre: string}>
     */
    public function getEsquemasCatalogoUsuario(User $user): array
    {
        $catalogo = $this->getCatalogoGrupos();
        $result   = [];

        foreach ($this->getGruposBd($user) as $grupoCodigo) {
            $schema = $this->extractSchema($grupoCodigo);
            if ($schema === '' || $schema === 'admin') {
                continue;
            }

            $meta = $catalogo[strtoupper($grupoCodigo)] ?? null;

            $result[] = [
                'schema' => $schema,
                'codigo' => $grupoCodigo,
                'nombre' => $meta['descripcion'] ?? strtoupper($schema),
            ];
        }

        return $result;
    }

    /**
     * Retorna metadatos del catálogo bi_grupos indexados por código.
     *
     * @return array<string, array{codigo: string, tipo: int, descripcion: ?string}>
     */
    public function getCatalogoGrupos(): array
    {
        if (!Schema::hasTable('bi_grupos')) {
            return [];
        }

        return BiGrupo::query()
            ->get(['codigo', 'tipo', 'descripcion'])
            ->keyBy(fn (BiGrupo $g) => strtoupper($g->codigo))
            ->map(fn (BiGrupo $g) => [
                'codigo'      => $g->codigo,
                'tipo'        => $g->tipo,
                'descripcion' => $g->descripcion,
            ])
            ->all();
    }

    /**
     * Enriquece la respuesta de vistas con labels del catálogo bi_grupos.
     */
    private function enrichViewsResponse(array $response, User $user): array
    {
        $catalogo = $this->getCatalogoGrupos();
        $grupos   = $this->getGruposBd($user);

        if (!isset($response['schemas']) || !is_array($response['schemas'])) {
            return $response;
        }

        foreach ($response['schemas'] as &$schemaBlock) {
            $schemaCode = strtoupper($schemaBlock['schema'] ?? '');
            $grupoCode  = 'GG-BD-' . $schemaCode;
            $meta       = $catalogo[$grupoCode] ?? null;

            if ($meta) {
                $schemaBlock['display'] = $meta['descripcion'];
            }
        }
        unset($schemaBlock);

        $response['grupos_catalogo'] = array_values(array_filter(
            array_map(fn ($g) => $catalogo[strtoupper($g)] ?? null, $grupos)
        ));

        return $response;
    }

    /**
     * Filtra los esquemas devueltos por Python según los grupos GG-BD-* del usuario.
     * Evita mostrar esquemas (ej. ca, co) si Python respondió como admin nacional.
     */
    private function filterViewsByUserSchemas(array $response, User $user): array
    {
        $allowed = $this->getEsquemasPermitidos($user);

        if (empty($allowed) || !isset($response['schemas']) || !is_array($response['schemas'])) {
            return $response;
        }

        $allowedLower = array_map('strtolower', $allowed);

        $response['schemas'] = array_values(array_filter(
            $response['schemas'],
            fn ($block) => in_array(strtolower($block['schema'] ?? ''), $allowedLower, true)
        ));

        $response['total_schemas'] = count($response['schemas']);
        $response['total_views']   = array_sum(
            array_map(fn ($block) => (int) ($block['view_count'] ?? count($block['views'] ?? [])), $response['schemas'])
        );
        $response['schemas_allowed'] = $allowed;
        $response['user']              = $user->email;

        return $response;
    }

    public function tieneAccesoEsquema(User $user, string $schema): bool
    {
        $esquemas = $this->getEsquemasPermitidos($user);
        return in_array(strtolower($schema), $esquemas, true);
    }

    // =========================================================================
    // ENDPOINTS DE LA API PYTHON
    // =========================================================================

    /**
     * Obtiene las vistas de Fabric que el usuario puede ver.
     * Si $schema está definido, consulta solo ese esquema (mucho más rápido).
     *
     * POST /api/catalog/views en la API Py
     *
     * @return array
     */
    public function getViewsForUser(User $user, ?string $schema = null, bool $forceRefresh = false): array
    {
        $grupos       = $this->getGruposBd($user);
        $departamento = $this->getDepartamento($user);

        if (empty($grupos)) {
            return [
                'success' => false,
                'message' => 'El usuario no tiene grupos GG-BD-* asignados.',
                'data'    => [],
            ];
        }

        if ($schema !== null && !$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
                'data'    => [],
            ];
        }

        $schemaKey = $schema ? strtolower($schema) : 'all';
        $cacheKey  = sprintf(
            'fabric_views:%d:%s:%s',
            $user->id,
            $schemaKey,
            md5(($departamento ?? '') . implode(',', $grupos))
        );

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $response = Cache::get($cacheKey);

        if ($response === null) {
            $payload = array_merge(
                $this->userContextPayload($user),
                ['token' => $this->tokenAdmin]
            );

            if ($schema !== null) {
                $payload['schema_name'] = strtolower($schema);
            }

            $response = $this->post('/api/catalog/views', $payload);

            if ($response !== null) {
                Cache::put($cacheKey, $response, 300);
            }
        }

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'No se pudo conectar con la API Graph-Fabric.',
                'data'    => [],
            ];
        }

        return [
            'success'           => true,
            'data'              => $this->filterViewsByUserSchemas(
                $this->enrichViewsResponse($response, $user),
                $user
            ),
            'grupos'            => $grupos,
            'esquemas'          => $this->getEsquemasPermitidos($user),
            'esquemas_catalogo' => $this->getEsquemasCatalogoUsuario($user),
            'departamento'      => $departamento,
        ];
    }

    /**
     * Obtiene las columnas de una vista específica.
     * Valida que el usuario tenga acceso al esquema antes de consultar.
     *
     * POST /api/catalog/columns en la API Py
     */
    public function getViewColumns(User $user, string $schema, string $viewName): array
    {
        if (!$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
            ];
        }

        $response = $this->post('/api/catalog/columns', array_merge(
            $this->userContextPayload($user),
            [
                'token'       => $this->tokenAdmin,
                'schema_name' => $schema,
                'view_name'   => $viewName,
            ]
        ));

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'Error al obtener columnas de la API Graph-Fabric.',
            ];
        }

        return [
            'success' => true,
            'data'    => $response,
        ];
    }

    /**
     * Consulta datos paginados de una vista de Fabric.
     * Valida acceso al esquema, luego hace proxy a /api/data/dynamic.
     *
     * @param array $options [
     *   'columns'  => string[],   // columnas a retornar, [] = todas
     *   'filters'  => array,      // filtros clave=>valor (soporta LIKE con %)
     *   'limit'    => int,        // filas por página (default 50, max 5000)
     *   'offset'   => int,        // paginación
     *   'sort_col' => string,     // columna para ordenar
     *   'sort_dir' => string,     // 'asc' | 'desc'
     * ]
     */
    public function queryViewData(User $user, string $schema, string $view, array $options = []): array
    {
        if (!$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
            ];
        }

        $limit  = min((int)($options['limit'] ?? 50), 5000);
        $offset = max(0, (int)($options['offset'] ?? 0));

        $payload = array_merge(
            $this->userContextPayload($user),
            [
                'token'       => $this->tokenAdmin,
                'schema_name' => $schema,
                'view'        => $view,
                'columns'     => $options['columns'] ?? [],
                'filters'     => $this->normalizeFilters($options['filters'] ?? []),
                'limit'       => $limit,
                'offset'      => $offset,
                'sort_col'    => $options['sort_col'] ?? '',
                'sort_dir'    => $options['sort_dir'] ?? 'asc',
            ]
        );

        $response = $this->post('/api/data/dynamic', $payload);

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'Error al consultar datos en la API Graph-Fabric.',
            ];
        }

        return [
            'success' => true,
            'data'    => $response['items'] ?? $response,
            'meta'    => $response['page_info'] ?? [
                'total'    => count($response['items'] ?? []),
                'limit'    => $limit,
                'offset'   => $offset,
                'has_next' => false,
            ],
        ];
    }

    /**
     * Exporta una vista a Excel.
     * Retorna el contenido binario del archivo .xlsx o null si falla.
     *
     * @param array $options [
     *   'columns'  => string[],
     *   'filters'  => array,
     *   'sort_col' => string,
     *   'sort_dir' => string,
     *   'max_rows' => int,   // default 100000, max 1048576
     * ]
     * @return array{success: bool, content: ?string, filename: ?string, message: ?string}
     */
    public function exportViewExcel(User $user, string $schema, string $view, array $options = []): array
    {
        if (!$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success' => false,
                'content' => null,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
            ];
        }

        $payload = array_merge(
            $this->userContextPayload($user),
            [
                'token'       => $this->tokenAdmin,
                'schema_name' => $schema,
                'view'        => $view,
                'columns'     => $options['columns'] ?? [],
                'filters'     => $this->normalizeFilters($options['filters'] ?? []),
                'sort_col'    => $options['sort_col'] ?? '',
                'sort_dir'    => $options['sort_dir'] ?? 'asc',
                'max_rows'    => min((int)($options['max_rows'] ?? 100000), 1048576),
            ]
        );

        try {
            $exportTimeout = max($this->timeout, 300);
            $apiKey = env('GRAPHQL_API_KEY', '');
            $req    = Http::timeout($exportTimeout)->acceptJson();
            if ($apiKey !== '') {
                $req = $req->withHeaders(['X-API-Key' => $apiKey]);
            }

            $response = $req->post($this->baseUrl . '/api/data/export/excel', $payload);

            if ($response->failed()) {
                Log::error('GraphFabricGateway export error', [
                    'status' => $response->status(),
                    'schema' => $schema,
                    'view'   => $view,
                ]);
                return [
                    'success' => false,
                    'content' => null,
                    'message' => "Error exportando: HTTP {$response->status()}",
                ];
            }

            // Obtener nombre del archivo desde el header si está disponible
            $disposition = $response->header('Content-Disposition') ?? '';
            preg_match('/filename="?([^";]+)"?/', $disposition, $matches);
            $filename = $matches[1] ?? "{$schema}_{$view}_" . date('Ymd_His') . '.xlsx';

            return [
                'success'  => true,
                'content'  => $response->body(),
                'filename' => $filename,
                'message'  => null,
            ];
        } catch (\Exception $e) {
            Log::error('GraphFabricGateway export exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'content' => null,
                'message' => 'Error exportando: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // HTTP HELPERS
    // =========================================================================

    /**
     * Contexto del usuario autenticado para Graph-Fabric.
     * Con TOKEN_ADMIN, Python usa estos datos en lugar del perfil admin hardcodeado.
     *
     * @return array{grupos: string[], department: ?string, user_email: string, user_name: string}
     */
    private function userContextPayload(User $user): array
    {
        return [
            'grupos'      => $this->getGruposBd($user),
            'department'  => $this->getDepartamento($user),
            'user_email'  => $user->email,
            'user_name'   => $user->name ?? $user->email,
        ];
    }

    /**
     * La API Python (FastAPI) exige filters como dict {}.
     * Un array vacío [] en PHP se serializa como [] y provoca HTTP 422.
     */
    private function normalizeFilters(mixed $filters): object|array
    {
        if (!is_array($filters) || $filters === [] || array_is_list($filters)) {
            return new \stdClass();
        }

        return $filters;
    }

    private function post(string $path, array $body): ?array
    {
        try {
            $apiKey = env('GRAPHQL_API_KEY', '');
            $req    = Http::timeout($this->timeout)->acceptJson();
            if ($apiKey !== '') {
                $req = $req->withHeaders(['X-API-Key' => $apiKey]);
            }

            $response = $req->post($this->baseUrl . $path, $body);

            if ($response->failed()) {
                Log::error('GraphFabricGateway POST error', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('GraphFabricGateway POST exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
