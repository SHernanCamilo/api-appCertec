<?php

namespace App\Services\Fabric;

use App\Models\User;
use App\Models\UserGrup;
use App\Models\BiGrupo;
use App\Models\BiVista;
use App\Models\BiVistaDelegacion;
use App\Models\BiVistaDelegacionUsuario;
use App\Models\Sede;
use App\Models\Sucursal;
use App\Services\Fabric\FabricCircuitBreaker;
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
 *   - Resolver sedes/sucursales desde seg_empresa_user (con recursivo)
 *   - Actuar como proxy seguro: validar que el usuario tiene acceso
 *     al esquema solicitado antes de reenviar la solicitud a la API Py
 *   - Reenviar solicitudes a la API Python con TOKEN_ADMIN + contexto del usuario
 */
class GraphFabricGatewayService
{
    private string $baseUrl;
    private string $tokenAdmin;
    private int    $timeout;
    private FabricCircuitBreaker $circuitBreaker;
    private BiVistasSyncService  $vistasSyncService;

    public function __construct()
    {
        $this->baseUrl            = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $this->tokenAdmin         = env('TOKEN_ADMIN', '');
        $this->timeout            = (int) env('GRAPHQL_TIMEOUT', 500);
        $this->circuitBreaker     = new FabricCircuitBreaker();
        $this->vistasSyncService  = new BiVistasSyncService();
    }

    // =========================================================================
    // HELPERS DE USUARIO
    // =========================================================================

    /**
     * Retorna los grupos GG-BD-* del usuario: asignados en users_grups + delegados por empresa.
     * Ej: ["GG-BD-IN", "GG-BD-RF"]
     *
     * @return string[]
     */
    public function getGruposBd(User $user, ?int $tipo = null): array
    {
        $grupos    = $this->getGruposBdDirectos($user, $tipo);
        $delegados = $this->getGruposDelegadosPorEmpresa($user, $tipo);
        $grupos    = array_values(array_unique(array_merge($grupos, $delegados)));

        if ($tipo === null) {
            return $grupos;
        }

        $catalogo = $this->getCatalogoGrupos();

        return array_values(array_filter(
            $grupos,
            fn ($g) => $this->resolveGrupoTipo($g, $catalogo) === $tipo
        ));
    }

    /**
     * Grupos GG-BD-* asignados directamente al usuario en users_grups.
     *
     * @return string[]
     */
    public function getGruposBdDirectos(User $user, ?int $tipo = null): array
    {
        $grupos = UserGrup::where('id_user', $user->id)
            ->where('tipo', UserGrup::TIPO_VISTA_BD)
            ->pluck('permiso')
            ->filter(fn ($g) => str_starts_with(strtoupper($g), 'GG-BD-'))
            ->values()
            ->all();

        if ($tipo === null) {
            return $grupos;
        }

        $catalogo = $this->getCatalogoGrupos();

        return array_values(array_filter(
            $grupos,
            fn ($g) => $this->resolveGrupoTipo($g, $catalogo) === $tipo
        ));
    }

    /**
     * Esquemas delegados a las empresas del usuario vía bi_vista_delegaciones.
     *
     * @return string[]  Códigos GG-BD-*
     */
    private function getGruposDelegadosPorEmpresa(User $user, ?int $tipo = null): array
    {
        if (!Schema::hasTable('bi_vista_delegaciones')) {
            return [];
        }

        $empresaIds = $user->empresas()->pluck('ent_empresas.id')->map(fn ($id) => (int) $id)->all();
        if ($empresaIds === []) {
            return [];
        }

        $grupoIds = BiVistaDelegacion::query()
            ->whereIn('empresa_id', $empresaIds)
            ->distinct()
            ->pluck('id_bi_grupos')
            ->all();

        if (Schema::hasTable('bi_vista_delegacion_usuarios')) {
            $grupoIdsUsuario = BiVistaDelegacionUsuario::query()
                ->where('user_id', $user->id)
                ->whereIn('empresa_id', $empresaIds)
                ->distinct()
                ->pluck('id_bi_grupos')
                ->all();

            $grupoIds = array_values(array_unique(array_merge($grupoIds, $grupoIdsUsuario)));
        }

        if ($grupoIds === []) {
            return [];
        }

        $query = BiGrupo::query()->whereIn('id', $grupoIds);
        if ($tipo !== null) {
            $query->where('tipo', $tipo);
        }

        return $query->get(['codigo'])
            ->map(function (BiGrupo $grupo) {
                $codigo = strtoupper(trim($grupo->codigo));

                return str_starts_with($codigo, 'GG-BD-') ? $codigo : 'GG-BD-' . $codigo;
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Busca metadatos del catálogo por código GG-BD-* o esquema corto (AA, CO…).
     *
     * @param  array<string, array{codigo: string, tipo: int, descripcion: ?string}>  $catalogo
     * @return array{codigo: string, tipo: int, descripcion: ?string}|null
     */
    private function resolveGrupoCatalogo(string $grupo, array $catalogo): ?array
    {
        $upper = strtoupper(trim($grupo));

        if (isset($catalogo[$upper])) {
            return $catalogo[$upper];
        }

        $schema = strtoupper($this->extractSchema($upper));
        if ($schema !== '') {
            if (isset($catalogo[$schema])) {
                return $catalogo[$schema];
            }
            $prefixed = 'GG-BD-' . $schema;
            if (isset($catalogo[$prefixed])) {
                return $catalogo[$prefixed];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{codigo: string, tipo: int, descripcion: ?string}>  $catalogo
     */
    private function resolveGrupoTipo(string $grupo, array $catalogo): ?int
    {
        return $this->resolveGrupoCatalogo($grupo, $catalogo)['tipo'] ?? null;
    }

    /**
     * Retorna el departamento del usuario (ej: "MA-TIC", "FLA-ADM").
     * Fuente: users_grups (Azure). Complementado por resolveSiteContext().
     */
    public function getDepartamento(User $user): ?string
    {
        return UserGrup::where('id_user', $user->id)
            ->where('tipo', UserGrup::TIPO_DEPARTAMENTO)
            ->value('permiso');
    }

    /**
     * Resuelve sedes efectivas del usuario para filtrar vistas:
     *   1) Asignaciones del aplicativo (seg_empresa_user + prefijos) — tienen prioridad
     *   2) Departamento Azure (users_grups) — solo si no hay restricción org por sede
     *
     * GRANT solo entiende un department. Si hay varias sedes:
     *   - Se envía NAL al GRANT para traer el catálogo completo
     *   - Laravel filtra por site_codes (no es nacional real)
     *
     * @return array{department: ?string, site_codes: string[], is_national: bool}
     */
    public function resolveSiteContext(User $user): array
    {
        $azureDepartment = $this->getDepartamento($user);
        $org             = $this->resolveOrgSiteAccess($user);

        // Empresa recursiva en el aplicativo → nacional
        if ($org['is_national']) {
            return [
                'department'  => 'NAL',
                'site_codes'  => ['NAL'],
                'is_national' => true,
            ];
        }

        // Si el usuario tiene sedes/sucursales concretas en el app, esas limitan el acceso
        if ($org['codes'] !== []) {
            $codes = $org['codes'];

            return [
                // Varias sedes: GRANT trae todo; Laravel recorta por site_codes
                'department'  => count($codes) === 1 ? $codes[0] : 'NAL',
                'site_codes'  => $codes,
                'is_national' => false,
            ];
        }

        // Sin restricción org → usar Azure
        if ($azureDepartment !== null && trim($azureDepartment) !== '') {
            $deptUpper = strtoupper(trim($azureDepartment));
            $parts     = preg_split('/[-\s]+/', $deptUpper) ?: [];

            if (in_array('NAL', $parts, true) || in_array('NAC', $parts, true)) {
                return [
                    'department'  => 'NAL',
                    'site_codes'  => ['NAL'],
                    'is_national' => true,
                ];
            }

            $azureCode = BiVista::extractSiteCode($azureDepartment);
            if ($azureCode !== null && in_array($azureCode, ['NAL', 'NAC', 'MA'], true)) {
                return [
                    'department'  => 'NAL',
                    'site_codes'  => ['NAL'],
                    'is_national' => true,
                ];
            }

            return [
                'department'  => $azureDepartment,
                'site_codes'  => $azureCode ? [$azureCode] : [],
                'is_national' => false,
            ];
        }

        return [
            'department'  => null,
            'site_codes'  => [],
            'is_national' => false,
        ];
    }

    /**
     * Prefijos desde asignaciones organizacionales del usuario.
     *
     * @return array{codes: string[], is_national: bool}
     */
    private function resolveOrgSiteAccess(User $user): array
    {
        $user->loadMissing('empresas');

        $codes      = [];
        $isNational = false;

        foreach ($user->empresas as $empresa) {
            $pivot      = $empresa->pivot;
            $sucursalId = $pivot->id_sucursal ?? null;
            $sedeId     = $pivot->id_sede ?? null;
            $recursivo  = (bool) ($pivot->recursivo ?? false);

            // Empresa completa (recursivo) → ve todas las sedes
            if ($recursivo && !$sucursalId && !$sedeId) {
                $isNational = true;
                continue;
            }

            // Sucursal recursiva → todas las sedes de esa sucursal
            if ($recursivo && $sucursalId && !$sedeId) {
                $sucursal = Sucursal::with('sedes')->find($sucursalId);
                if ($sucursal === null) {
                    continue;
                }

                $prefijoSucursal = $this->normalizarPrefijoSite($sucursal->prefijo ?? null);
                if ($prefijoSucursal !== null) {
                    $codes[] = $prefijoSucursal;
                }

                foreach ($sucursal->sedes as $sede) {
                    $prefijoSede = $this->normalizarPrefijoSite($sede->prefijo ?? null)
                        ?? $prefijoSucursal;
                    if ($prefijoSede !== null) {
                        $codes[] = $prefijoSede;
                    }
                }
                continue;
            }

            // Sede específica
            if ($sedeId) {
                $sede = Sede::with('sucursal')->find($sedeId);
                if ($sede === null) {
                    continue;
                }

                $prefijo = $this->normalizarPrefijoSite($sede->prefijo ?? null)
                    ?? $this->normalizarPrefijoSite($sede->sucursal->prefijo ?? null);
                if ($prefijo !== null) {
                    $codes[] = $prefijo;
                }
                continue;
            }

            // Sucursal sin recursivo (solo ese nodo)
            if ($sucursalId) {
                $sucursal = Sucursal::find($sucursalId);
                $prefijo  = $this->normalizarPrefijoSite($sucursal->prefijo ?? null);
                if ($prefijo !== null) {
                    $codes[] = $prefijo;
                }
            }
        }

        return [
            'codes'       => array_values(array_unique(array_filter($codes))),
            'is_national' => $isNational,
        ];
    }

    private function normalizarPrefijoSite(?string $prefijo): ?string
    {
        $prefijo = strtoupper(trim((string) $prefijo));

        return $prefijo === '' ? null : $prefijo;
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
    public function getEsquemasPermitidos(User $user, ?int $tipo = null): array
    {
        return collect($this->getGruposBd($user, $tipo))
            ->map(fn ($g) => $this->extractSchema($g))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Esquemas permitidos del usuario con nombre desde bi_grupos.
     *
     * @return array<int, array{schema: string, codigo: string, nombre: string, tipo: ?int, es_delegado: bool, empresa_id: ?int, empresa_nombre: ?string}>
     */
    public function getEsquemasCatalogoUsuario(User $user, ?int $tipo = null): array
    {
        $catalogo   = $this->getCatalogoGrupos();
        $directos   = array_flip(array_map('strtoupper', $this->getGruposBdDirectos($user, $tipo)));
        $result     = [];

        foreach ($this->getGruposBd($user, $tipo) as $grupoCodigo) {
            $schema = $this->extractSchema($grupoCodigo);
            if ($schema === '' || $schema === 'admin') {
                continue;
            }

            $meta       = $catalogo[strtoupper($grupoCodigo)] ?? null;
            if ($meta === null) {
                $meta = $this->resolveGrupoCatalogo($grupoCodigo, $catalogo);
            }

            $esDelegado = !isset($directos[strtoupper($grupoCodigo)]);
            $empresaId  = null;
            $empresaNom = null;

            if ($esDelegado) {
                $grupo = $this->resolveBiGrupoByCodigo($grupoCodigo);
                $empresaId  = $grupo?->empresa_id;
                $empresaNom = $grupo?->empresa?->nombre;
            }

            $result[] = [
                'schema'          => $schema,
                'codigo'          => $grupoCodigo,
                'nombre'          => $meta['descripcion'] ?? strtoupper($schema),
                'tipo'            => $meta['tipo'] ?? null,
                'es_delegado'     => $esDelegado,
                'empresa_id'      => $empresaId,
                'empresa_nombre'  => $empresaNom,
            ];
        }

        return $result;
    }

    /**
     * Resuelve bi_grupos por código GG-BD-* o esquema corto (RF).
     */
    private function resolveBiGrupoByCodigo(string $grupoCodigo): ?BiGrupo
    {
        $upper  = strtoupper(trim($grupoCodigo));
        $schema = strtoupper($this->extractSchema($upper));

        return BiGrupo::query()
            ->where(function ($q) use ($upper, $schema) {
                $q->whereRaw('UPPER(codigo) = ?', [$upper]);
                if ($schema !== '') {
                    $q->orWhereRaw('UPPER(codigo) = ?', [$schema])
                        ->orWhereRaw('UPPER(codigo) = ?', ['GG-BD-' . $schema]);
                }
            })
            ->with('empresa:id,nombre')
            ->first();
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

        $index = [];

        foreach (BiGrupo::query()->get(['codigo', 'tipo', 'descripcion']) as $grupo) {
            $meta = [
                'codigo'      => $grupo->codigo,
                'tipo'        => $grupo->tipo,
                'descripcion' => $grupo->descripcion,
            ];

            $codigo = strtoupper(trim($grupo->codigo));
            $index[$codigo] = $meta;

            if (str_starts_with($codigo, 'GG-BD-')) {
                $schema = strtoupper($this->extractSchema($codigo));
                if ($schema !== '') {
                    $index[$schema]           = $meta;
                    $index['GG-BD-' . $schema] = $meta;
                }
            } else {
                $index['GG-BD-' . $codigo] = $meta;
            }
        }

        return $index;
    }

    /**
     * Enriquece la respuesta de vistas con labels del catálogo bi_grupos.
     */
    private function enrichViewsResponse(array $response, User $user, ?int $tipo = null): array
    {
        $catalogo = $this->getCatalogoGrupos();
        $grupos   = $this->getGruposBd($user, $tipo);

        if (!isset($response['schemas']) || !is_array($response['schemas'])) {
            return $response;
        }

        foreach ($response['schemas'] as &$schemaBlock) {
            $schemaCode = strtoupper($schemaBlock['schema'] ?? '');
            $grupoCode  = 'GG-BD-' . $schemaCode;
            $meta       = $this->resolveGrupoCatalogo($grupoCode, $catalogo);

            if ($meta) {
                $schemaBlock['display'] = $meta['descripcion'];
            }
        }
        unset($schemaBlock);

        $response['grupos_catalogo'] = array_values(array_filter(
            array_map(fn ($g) => $this->resolveGrupoCatalogo($g, $catalogo), $grupos)
        ));

        return $response;
    }

    /**
     * Filtra los esquemas devueltos por Python según los grupos GG-BD-* del usuario.
     * Evita mostrar esquemas (ej. ca, co) si Python respondió como admin nacional.
     */
    private function filterViewsByUserSchemas(array $response, User $user, ?int $tipo = null): array
    {
        $allowed = $this->getEsquemasPermitidos($user, $tipo);

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

    /**
     * Filtra vistas por prefijos de sede del usuario (post-GRANT).
     * Necesario cuando el usuario tiene varias sedes: GRANT recibe NAL
     * y aquí se dejan solo las vistas de sus site_codes (ej: EAL, NVA).
     */
    private function filterViewsBySiteCodes(array $response, User $user): array
    {
        if (!isset($response['schemas']) || !is_array($response['schemas'])) {
            return $response;
        }

        $siteContext = $this->resolveSiteContext($user);
        if ($siteContext['is_national'] || $siteContext['site_codes'] === []) {
            return $response;
        }

        $allowed = array_map('strtolower', $siteContext['site_codes']);
        $known   = $this->knownSiteCodesLower();

        foreach ($response['schemas'] as &$schemaBlock) {
            $schemaBlock['views'] = array_values(array_filter(
                $schemaBlock['views'] ?? [],
                function ($view) use ($allowed, $known) {
                    $name = strtolower((string) ($view['view_name'] ?? ''));
                    if ($name === '') {
                        return false;
                    }

                    $hasAnyKnownSite = false;
                    foreach ($known as $code) {
                        if (str_contains($name, $code)) {
                            $hasAnyKnownSite = true;
                            break;
                        }
                    }

                    // Vistas sin sede en el nombre = nacionales/padre → no visibles a usuario de sede
                    if (!$hasAnyKnownSite) {
                        return false;
                    }

                    foreach ($allowed as $code) {
                        if (str_contains($name, $code)) {
                            return true;
                        }
                    }

                    return false;
                }
            ));

            $schemaBlock['view_count'] = count($schemaBlock['views']);
        }
        unset($schemaBlock);

        $response['total_views'] = array_sum(
            array_map(fn ($block) => count($block['views'] ?? []), $response['schemas'])
        );

        return $response;
    }

    /**
     * Códigos de sede conocidos en nombres de vistas Fabric.
     *
     * @return string[]
     */
    private function knownSiteCodesLower(): array
    {
        return ['cmi', 'eal', 'fla', 'kta', 'tja', 'nva', 'dta', 'pto', 'nal'];
    }

    /**
     * Valida que el usuario pueda ver una vista concreta por sede.
     */
    public function tieneAccesoVistaPorSede(User $user, string $viewName): bool
    {
        $siteContext = $this->resolveSiteContext($user);
        if ($siteContext['is_national']) {
            return true;
        }

        if ($siteContext['site_codes'] === []) {
            return true;
        }

        $name    = strtolower($viewName);
        $allowed = array_map('strtolower', $siteContext['site_codes']);
        $known   = $this->knownSiteCodesLower();

        $hasAnyKnownSite = false;
        foreach ($known as $code) {
            if (str_contains($name, $code)) {
                $hasAnyKnownSite = true;
                break;
            }
        }

        if (!$hasAnyKnownSite) {
            return false;
        }

        foreach ($allowed as $code) {
            if (str_contains($name, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Aplica restricciones de departamento definidas en bi_vistas.
     * Ej: VW_AG_Agendas solo para MA y NAL aunque Fabric la muestre a todas las sedes.
     */
    private function filterViewsByBiVistasDepartamento(array $response, User $user): array
    {
        if (!isset($response['schemas']) || !is_array($response['schemas']) || !Schema::hasTable('bi_vistas')) {
            return $response;
        }

        $siteContext = $this->resolveSiteContext($user);
        $siteCodes   = $siteContext['site_codes'];
        $isNational  = $siteContext['is_national'];
        $configIndex = $this->getBiVistasConfigBySchema();

        foreach ($response['schemas'] as &$schemaBlock) {
            $schema  = strtolower($schemaBlock['schema'] ?? '');
            $configs = $configIndex[$schema] ?? [];

            if ($configs === []) {
                continue;
            }

            $byNombre = [];
            foreach ($configs as $cfg) {
                $byNombre[strtolower($cfg['nombre'])] = $cfg;
            }

            $schemaBlock['views'] = array_values(array_filter(
                $schemaBlock['views'] ?? [],
                function ($view) use ($byNombre, $siteCodes, $isNational) {
                    $nombre = strtolower($view['view_name'] ?? '');
                    $cfg    = $byNombre[$nombre] ?? null;

                    if ($cfg === null) {
                        return true;
                    }

                    $vista = new BiVista([
                        'nombre'        => $cfg['nombre'],
                        'departamentos' => $cfg['departamentos'],
                    ]);

                    return $vista->visibleParaSiteCodes($siteCodes, $isNational);
                }
            ));

            $schemaBlock['view_count'] = count($schemaBlock['views']);
        }
        unset($schemaBlock);

        $response['total_views'] = array_sum(
            array_map(fn ($block) => count($block['views'] ?? []), $response['schemas'])
        );

        return $response;
    }

    /**
     * Restringe vistas según delegación empresa ↔ bi_vistas.
     * Si la empresa del usuario NO tiene filas en bi_vista_delegaciones para el esquema, no restringe.
     */
    private function filterViewsByDelegacion(array $response, User $user): array
    {
        if (!isset($response['schemas']) || !is_array($response['schemas']) || !Schema::hasTable('bi_vista_delegaciones')) {
            return $response;
        }

        $empresaIds = $user->empresas()->pluck('ent_empresas.id')->map(fn ($id) => (int) $id)->all();
        if ($empresaIds === []) {
            return $response;
        }

        $delegacionIndex       = $this->getDelegacionIndex();
        $userDelegacionIndex   = $this->getDelegacionUsuarioIndex();

        foreach ($response['schemas'] as &$schemaBlock) {
            $schema  = strtolower($schemaBlock['schema'] ?? '');
            $grupoId = $this->resolveGrupoIdBySchema($schema);

            if ($grupoId === null) {
                continue;
            }

            $schemaBlock['views'] = array_values(array_filter(
                $schemaBlock['views'] ?? [],
                function ($view) use ($empresaIds, $delegacionIndex, $userDelegacionIndex, $grupoId, $user) {
                    $nombre = strtolower($view['view_name'] ?? '');

                    foreach ($empresaIds as $empresaId) {
                        $userKey = $user->id . '.' . $empresaId . '.' . $grupoId;

                        if (isset($userDelegacionIndex[$userKey])) {
                            $permitidos = array_map('strtolower', $userDelegacionIndex[$userKey]);
                            if (in_array($nombre, $permitidos, true)) {
                                return true;
                            }
                            continue;
                        }

                        $key = $empresaId . '.' . $grupoId;

                        if (!isset($delegacionIndex[$key])) {
                            return true;
                        }

                        $permitidos = array_map('strtolower', $delegacionIndex[$key]);
                        if (in_array($nombre, $permitidos, true)) {
                            return true;
                        }
                    }

                    return false;
                }
            ));

            $schemaBlock['view_count'] = count($schemaBlock['views']);
        }
        unset($schemaBlock);

        $response['total_views'] = array_sum(
            array_map(fn ($block) => count($block['views'] ?? []), $response['schemas'])
        );

        return $response;
    }

    /**
     * @return array<string, array<int, string>>  "empresa_id.grupo_id" => [nombre_vista, ...]
     */
    private function getDelegacionIndex(): array
    {
        return Cache::remember('bi_vista_delegaciones_index', 300, function () {
            $index = [];

            BiVistaDelegacion::query()
                ->with(['vista:id,nombre'])
                ->get(['id', 'empresa_id', 'id_bi_grupos', 'id_bi_vista'])
                ->each(function (BiVistaDelegacion $row) use (&$index) {
                    $nombre = $row->vista?->nombre;
                    if ($nombre === null) {
                        return;
                    }

                    $key = $row->empresa_id . '.' . $row->id_bi_grupos;
                    $index[$key][] = $nombre;
                });

            return $index;
        });
    }

    /**
     * @return array<string, array<int, string>>  "user_id.empresa_id.grupo_id" => [nombre_vista, ...]
     */
    private function getDelegacionUsuarioIndex(): array
    {
        if (!Schema::hasTable('bi_vista_delegacion_usuarios')) {
            return [];
        }

        return Cache::remember('bi_vista_delegacion_usuarios_index', 300, function () {
            $index = [];

            BiVistaDelegacionUsuario::query()
                ->with(['vista:id,nombre'])
                ->get(['user_id', 'empresa_id', 'id_bi_grupos', 'id_bi_vista'])
                ->each(function (BiVistaDelegacionUsuario $row) use (&$index) {
                    $nombre = $row->vista?->nombre;
                    if ($nombre === null) {
                        return;
                    }

                    $key           = $row->user_id . '.' . $row->empresa_id . '.' . $row->id_bi_grupos;
                    $index[$key][] = $nombre;
                });

            return $index;
        });
    }

    /**
     * Resuelve id de bi_grupos por esquema corto (rf) o código (GG-BD-RF).
     */
    private function resolveGrupoIdBySchema(string $schema): ?int
    {
        $schema = strtolower(trim($schema));
        if ($schema === '') {
            return null;
        }

        return BiGrupo::query()
            ->where(function ($q) use ($schema) {
                $q->whereRaw('LOWER(codigo) = ?', [$schema])
                    ->orWhereRaw('LOWER(codigo) = ?', ['gg-bd-' . $schema]);
            })
            ->value('id');
    }

    /**
     * @return array<string, array<int, array{nombre: string, departamentos: ?array}>>
     */
    private function getBiVistasConfigBySchema(): array
    {
        return Cache::remember('bi_vistas_depto_config', 300, function () {
            $index = [];

            BiVista::query()
                ->with('grupo:id,codigo')
                ->get(['id', 'id_bi_grupos', 'nombre', 'departamentos'])
                ->each(function (BiVista $vista) use (&$index) {
                    $codigo = $vista->grupo?->codigo;
                    if ($codigo === null) {
                        return;
                    }

                    $schema = strtolower($codigo);
                    $index[$schema][] = [
                        'nombre'        => $vista->nombre,
                        'departamentos' => $vista->departamentos,
                    ];
                });

            return $index;
        });
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
    public function getViewsForUser(User $user, ?string $schema = null, bool $forceRefresh = false, ?int $tipo = null): array
    {
        $grupos       = $this->getGruposBd($user, $tipo);
        $siteContext  = $this->resolveSiteContext($user);
        $departamento = $siteContext['department'];
        $siteCodes    = $siteContext['site_codes'];

        if (empty($grupos)) {
            $mensaje = $tipo === null
                ? 'El usuario no tiene grupos GG-BD-* asignados.'
                : 'El usuario no tiene grupos asignados para este tipo de reporte.';

            return [
                'success' => false,
                'message' => $mensaje,
                'data'    => [],
            ];
        }

        if ($schema !== null && !$this->tieneAccesoEsquema($user, $schema, $tipo)) {
            return [
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
                'data'    => [],
            ];
        }

        $schemaKey = $schema ? strtolower($schema) : 'all';
        $tipoKey   = $tipo ?? 'all';
        $cacheKey  = sprintf(
            'fabric_views:%d:%s:%s:%s',
            $user->id,
            $schemaKey,
            $tipoKey,
            md5(($departamento ?? '') . implode(',', $siteCodes) . implode(',', $grupos))
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

        // Sincronizar vistas nuevas de Fabric → bi_vistas (auto-registro)
        $this->vistasSyncService->syncFromCatalogResponse($response);

        // Filtrar vistas inactivas y anotar bi_estado (mantenimiento visible pero bloqueado)
        $filteredResponse = $this->vistasSyncService->filterByEstado($response);

        return [
            'success'           => true,
            'data'              => $this->filterViewsBySiteCodes(
                $this->filterViewsByDelegacion(
                    $this->filterViewsByBiVistasDepartamento(
                        $this->filterViewsByUserSchemas(
                            $this->enrichViewsResponse($filteredResponse, $user, $tipo),
                            $user,
                            $tipo
                        ),
                        $user
                    ),
                    $user
                ),
                $user
            ),
            'grupos'            => $grupos,
            'esquemas'          => $this->getEsquemasPermitidos($user, $tipo),
            'esquemas_catalogo' => $this->getEsquemasCatalogoUsuario($user, $tipo),
            'departamento'      => $departamento,
            'site_codes'        => $siteCodes,
            'is_national'       => $siteContext['is_national'],
            'tipo'              => $tipo,
        ];
    }

    /**
     * Catálogo de vistas Fabric para un esquema (configuración admin).
     * Usa TOKEN_ADMIN sin filtrar por grupos del usuario.
     *
     * @return array{success: bool, message?: string, schema?: string, data: array<int, array{view_name: string, qualified_name: string, column_count: int}>}
     */
    public function getCatalogViewsForSchema(string $schema, bool $forceRefresh = false): array
    {
        $schema = strtolower(trim($schema));
        if ($schema === '') {
            return [
                'success' => false,
                'message' => 'Esquema inválido.',
                'data'    => [],
            ];
        }

        $cacheKey = 'fabric_catalog_admin:' . $schema;

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $response = Cache::get($cacheKey);

        if ($response === null) {
            if ($this->tokenAdmin === '') {
                return [
                    'success' => false,
                    'message' => 'TOKEN_ADMIN no está configurado en el servidor.',
                    'data'    => [],
                ];
            }

            $response = $this->post('/api/catalog/views', array_merge(
                $this->catalogAdminContextPayload(),
                [
                    'token'       => $this->tokenAdmin,
                    'schema_name' => $schema,
                ]
            ));

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

        $views = [];

        foreach ($response['schemas'] ?? [] as $block) {
            foreach ($block['views'] ?? [] as $view) {
                $views[] = [
                    'view_name'      => $view['view_name'] ?? '',
                    'qualified_name' => $view['qualified_name'] ?? '',
                    'column_count'   => (int) ($view['column_count'] ?? 0),
                ];
            }
        }

        usort($views, fn ($a, $b) => strcasecmp($a['view_name'], $b['view_name']));

        return [
            'success' => true,
            'schema'  => $schema,
            'data'    => $views,
        ];
    }

    public function tieneAccesoEsquema(User $user, string $schema, ?int $tipo = null): bool
    {
        $esquemas = $this->getEsquemasPermitidos($user, $tipo);
        return in_array(strtolower($schema), $esquemas, true);
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

        if (!$this->tieneAccesoVistaPorSede($user, $viewName)) {
            return [
                'success' => false,
                'message' => "Sin acceso a la vista '{$viewName}' por sede.",
                'code'    => 403,
            ];
        }

        $response = $this->post('/api/catalog/columns', array_merge(
            $this->userContextPayload($user, $viewName),
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

        if (!$this->tieneAccesoVistaPorSede($user, $view)) {
            return [
                'success' => false,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
                'code'    => 403,
            ];
        }

        // Validar estado de la vista (mantenimiento/inactiva)
        $estadoCheck = $this->vistasSyncService->checkVistaEstado($schema, $view);
        if (!$estadoCheck['activa']) {
            return [
                'success' => false,
                'message' => $estadoCheck['mensaje'] ?? "Vista no disponible.",
                'code'    => 503,
                'estado'  => $estadoCheck['estado'],
            ];
        }

        // Circuit breaker: reject rápido si la API Py no responde
        if (!$this->circuitBreaker->isAvailable()) {
            return [
                'success' => false,
                'message' => 'Servicio temporalmente no disponible. Reintente en unos segundos.',
                'code'    => 503,
            ];
        }

        $limit  = min((int)($options['limit'] ?? 50), 10000);
        $offset = max(0, (int)($options['offset'] ?? 0));

        $payload = array_merge(
            $this->userContextPayload($user, $view),
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
                'skip_count'  => ($options['skip_count'] ?? false) || $limit > 1000,
            ]
        );

        // Cache de queries: misma consulta exacta → respuesta cacheada 30s
        $cacheKey = 'fabric_qry:' . md5(json_encode($payload));
        $cacheTtl = (int) env('FABRIC_QUERY_CACHE_TTL', 30);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->post('/api/data/dynamic', $payload);

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'Error al consultar datos en la API Graph-Fabric.',
            ];
        }

        // La API Python detectó vista pesada sin filtros → propagar al controller
        if (!empty($response['__filters_required'])) {
            return [
                'success'          => false,
                'requires_filters' => true,
                'code'             => 422,
                'message'          => $response['message'],
                'suggestions'      => $response['suggestions'] ?? [],
                'columns'          => $response['columns'] ?? [],
                'heavy_view'       => true,
                'schema'           => $response['schema'] ?? $schema,
                'view_name'        => $response['view_name'] ?? $view,
            ];
        }

        $result = [
            'success' => true,
            'data'    => $response['items'] ?? $response,
            'meta'    => $response['page_info'] ?? [
                'total'    => count($response['items'] ?? []),
                'limit'    => $limit,
                'offset'   => $offset,
                'has_next' => false,
            ],
        ];

        // Solo cachear si hay datos válidos
        if (!empty($result['data'])) {
            Cache::put($cacheKey, $result, $cacheTtl);
        }

        return $result;
    }

    /**
     * Exporta una vista a Excel o NDJSON+Gzip.
     *
     * @param array $options [
     *   'columns'  => string[],
     *   'filters'  => array,
     *   'sort_col' => string,
     *   'sort_dir' => string,
     *   'max_rows' => int,
     *   'format'   => 'gzip'|'excel' (default: 'gzip' — 10x más rápido)
     * ]
     * @return array{success: bool, content: ?string, filename: ?string, format: string, message: ?string}
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

        if (!$this->tieneAccesoVistaPorSede($user, $view)) {
            return [
                'success' => false,
                'content' => null,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
                'code'    => 403,
            ];
        }

        $userContext = $this->userContextPayload($user, $view);
        $payload = array_merge(
            [
                'token'        => $this->tokenAdmin,
                'user_context' => $userContext,
            ],
            $userContext,
            [
                'schema_name' => $schema,
                'view'        => $view,
                'columns'     => $options['columns'] ?? [],
                'filters'     => $this->normalizeFilters($options['filters'] ?? []),
                'sort_col'    => $options['sort_col'] ?? '',
                'sort_dir'    => $options['sort_dir'] ?? 'asc',
                'max_rows'    => min((int)($options['max_rows'] ?? 100000), 1048576),
                'format'      => $options['format'] ?? 'gzip',
            ]
        );

        try {
            $exportTimeout = max($this->timeout, 300);
            $this->ensurePhpTimeLimit($exportTimeout);
            $apiKey = env('GRAPHQL_API_KEY', '');
            $req    = Http::timeout($exportTimeout)
                         ->connectTimeout(10)
                         ->acceptJson();

            if ($apiKey !== '') {
                $req = $req->withHeaders(['X-API-Key' => $apiKey]);
            }

            // Ambos endpoints requieren el token en el body (ya está en $payload)
            $format   = $options['format'] ?? 'gzip';
            // Ambos formatos usan /api/data/export/excel — el campo 'format' en el body
            // le indica a la API Py si devolver gzip o xlsx
            $endpoint = '/api/data/export/excel';

            Log::debug('GraphFabricGateway export payload', [
                'endpoint' => $endpoint,
                'token'    => substr($payload['token'] ?? '', 0, 10) . '...',
                'groups'   => $payload['groups'] ?? 'MISSING',
                'department' => $payload['department'] ?? 'MISSING',
                'schema'   => $payload['schema_name'] ?? 'MISSING',
                'view'     => $payload['view'] ?? 'MISSING',
                'format'   => $payload['format'] ?? 'MISSING',
            ]);

            $response = $req->post($this->baseUrl . $endpoint, $payload);

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
            $ext      = ($options['format'] ?? 'gzip') === 'gzip' ? '.ndjson.gz' : '.xlsx';
            $filename = $matches[1] ?? "{$schema}_{$view}_" . date('Ymd_His') . $ext;

            return [
                'success'  => true,
                'content'  => $response->body(),
                'filename' => $filename,
                'format'   => $options['format'] ?? 'gzip',
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
    // CONSULTA COMO SISTEMA (JOBS INTERNOS)
    // =========================================================================

    /**
     * Consulta directa como sistema (sin validar usuario).
     * Usado por Jobs internos que necesitan datos de Fabric sin un request HTTP.
     *
     * @param string $schema Esquema de la vista (ej: 'ex')
     * @param string $view   Nombre de la vista
     * @param array  $options Opciones: columns, filters, limit, offset, sort_col, sort_dir
     * @return array{success: bool, data: array, meta?: array, message?: string}
     */
    public function queryAsSystem(string $schema, string $view, array $options = []): array
    {
        $limit  = min((int)($options['limit'] ?? 500), 20000);
        $offset = max(0, (int)($options['offset'] ?? 0));

        $payload = [
            'token'       => $this->tokenAdmin,
            'groups'      => ['GG-BD-' . strtoupper($schema), 'GG-BD-ADMIN'],
            'department'  => 'NAL-TIC NAL',  // NAL = Nacional, sin filtro de sede
            'user_email'  => env('NOTIF_ADMIN_EMAIL', 'sistema@medilaser.com.co'),
            'user_name'   => 'Sistema Notificaciones',
            'schema_name' => $schema,
            'view'        => $view,
            'columns'     => $options['columns'] ?? [],
            'filters'     => $this->normalizeFilters($options['filters'] ?? []),
            'limit'       => $limit,
            'offset'      => $offset,
            'sort_col'    => $options['sort_col'] ?? '',
            'sort_dir'    => $options['sort_dir'] ?? 'asc',
        ];

        if (!$this->circuitBreaker->isAvailable()) {
            return ['success' => false, 'message' => 'Circuit breaker OPEN', 'data' => []];
        }

        $response = $this->post('/api/data/dynamic', $payload);

        if ($response === null) {
            return ['success' => false, 'message' => 'Error conectando a Graph-Fabric', 'data' => []];
        }

        return [
            'success' => true,
            'data'    => $response['items'] ?? [],
            'meta'    => $response['page_info'] ?? ['total' => 0],
        ];
    }

    /**
     * Ejecutar agregación (GROUP BY) en una vista de Fabric.
     * Devuelve datos resumidos para tablas dinámicas.
     */
    public function aggregate(User $user, string $schema, string $view, array $options): array
    {
        if (!$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
            ];
        }

        if (!$this->tieneAccesoVistaPorSede($user, $view)) {
            return [
                'success' => false,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
                'code'    => 403,
            ];
        }

        if (!$this->circuitBreaker->isAvailable()) {
            return [
                'success' => false,
                'message' => 'Servicio temporalmente no disponible.',
                'code'    => 503,
            ];
        }

        $payload = array_merge(
            $this->userContextPayload($user, $view),
            [
                'token'       => $this->tokenAdmin,
                'schema_name' => $schema,
                'view'        => $view,
                'rows'        => $options['rows'] ?? [],
                'columns'     => $options['columns'] ?? [],
                'values'      => $options['values'] ?? [],
                'filters'     => $this->normalizeFiltersPublic($options['filters'] ?? []),
                'limit'       => min((int)($options['limit'] ?? 10000), 50000),
                'sort_col'    => $options['sort_col'] ?? '',
                'sort_dir'    => $options['sort_dir'] ?? 'asc',
            ]
        );

        $cacheKey = 'fabric_agg:' . md5(json_encode($payload));
        $cacheTtl = 300; // 5 minutos

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->post('/api/data/aggregate', $payload);

        if ($response === null) {
            return [
                'success' => false,
                'message' => 'Error al ejecutar agregación en Graph-Fabric.',
                'code'    => 502,
            ];
        }

        $result = [
            'success'     => true,
            'data'        => $response['items'] ?? [],
            'aggregation' => $response['aggregation'] ?? [],
            'meta'        => $response['page_info'] ?? ['total_groups' => 0],
        ];

        Cache::put($cacheKey, $result, $cacheTtl);

        return $result;
    }

    // =========================================================================
    // HTTP HELPERS
    // =========================================================================

    /**
     * Contexto nacional admin para catálogo Fabric (parámetros BI).
     * Graph-Fabric exige TOKEN_ADMIN + groups/department; sin esto responde 401.
     *
     * @return array{groups: string[], department: string, user_email: string, user_name: string}
     */
    private function catalogAdminContextPayload(?User $user = null): array
    {
        $user = $user ?? auth()->user();

        return [
            'groups'      => ['GG-BD-ADMIN'],
            'department'  => 'NAL',
            'user_email'  => $user?->email ?? 'admin@medilaser.com.co',
            'user_name'   => $user?->name ?? $user?->email ?? 'Administrador BI',
        ];
    }

    /**
     * Department a enviar al GRANT para una vista concreta.
     * GRANT solo acepta un department: si el usuario tiene varias sedes,
     * se envía el prefijo que coincide con el nombre de la vista.
     */
    public function resolveDepartmentForGrantView(User $user, ?string $viewName = null): ?string
    {
        $siteContext = $this->resolveSiteContext($user);

        if ($siteContext['is_national']) {
            return 'NAL';
        }

        $codes = $siteContext['site_codes'];
        if ($codes === []) {
            return $siteContext['department'];
        }

        if ($viewName === null || trim($viewName) === '') {
            return $siteContext['department'];
        }

        $viewLower = strtolower($viewName);
        $matched   = [];
        foreach ($codes as $code) {
            if (str_contains($viewLower, strtolower($code))) {
                $matched[] = $code;
            }
        }

        if (count($matched) === 1) {
            return $matched[0];
        }

        // Varias coincidencias o vista sin sufijo claro → NAL para que GRANT no bloquee
        if (count($codes) > 1) {
            return 'NAL';
        }

        return $codes[0];
    }

    /**
     * Contexto del usuario autenticado para Graph-Fabric (GRANT).
     * Solo envía lo que el GRANT entiende: groups + department.
     * Si se indica $viewName, el department se alinea a esa vista (multi-sede).
     *
     * @return array{groups: string[], department: ?string, user_email: string, user_name: string}
     */
    public function userContextPayload(User $user, ?string $viewName = null): array
    {
        return [
            'groups'     => $this->getGruposBd($user),
            'department' => $this->resolveDepartmentForGrantView($user, $viewName),
            'user_email' => $user->email,
            'user_name'  => $user->name ?? $user->email,
        ];
    }

    /**
     * La API Python (FastAPI) exige filters como dict {}.
     * Un array vacío [] en PHP se serializa como [] y provoca HTTP 422.
     * También convierte fechas dd/mm/yyyy → yyyy-mm-dd para SQL Server.
     */
    private function normalizeFilters(mixed $filters): object|array
    {
        if (!is_array($filters) || $filters === [] || array_is_list($filters)) {
            return new \stdClass();
        }

        // Convertir fechas al formato ISO (yyyy-mm-dd) que SQL Server espera.
        // Filtros de fecha con ".." se omiten (se manejan client-side).
        $normalized = [];
        foreach ($filters as $key => $value) {
            $result = $this->normalizeFilterValue($value);
            if ($result !== null) {
                $normalized[$key] = $result;
            }
        }

        return empty($normalized) ? new \stdClass() : $normalized;
    }

    /**
     * Wrapper público de normalizeFilters para uso desde Jobs.
     */
    public function normalizeFiltersPublic(mixed $filters): object|array
    {
        return $this->normalizeFilters($filters);
    }

    /**
     * Normaliza un valor de filtro. Si es una fecha en formato local
     * (dd/mm/yyyy, d/m/yyyy, dd-mm-yyyy, con u sin hora) la convierte a ISO.
     * Los valores ya en ISO (yyyy-mm-dd[ T]hh:mm:ss) se dejan intactos.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function normalizeFilterValue(mixed $value): mixed
    {
        // Rango de fechas: {"from": "...", "to": "..."} o lista [desde, hasta]
        if (is_array($value)) {
            return array_map(fn ($v) => $this->normalizeFilterValue($v), $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        // Rango de fechas con ".." (ej: "2026-07-16..2026-07-18")
        // NOTA: La API Python no soporta operadores ni BETWEEN en columnas datetime.
        // SQL Server rechaza LIKE y operadores en datetime2.
        // Los filtros de fecha se manejan client-side en Ag-Grid (filterParams.comparator).
        // NO enviar al backend — retornar null para que se omita.
        if (str_contains($trimmed, '..') && preg_match('#^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$#', $trimmed)) {
            return null; // Se omite — filtrado client-side en Ag-Grid
        }

        // Operadores de comparación (>, <, >=, <=) — solo para columnas no-datetime
        if (preg_match('#^([><!]=?)(.+)$#', $trimmed)) {
            return null; // No soportado por Python actualmente
        }

        // Ya viene en ISO (yyyy-mm-dd, con hora opcional) → no tocar.
        if (preg_match('#^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$#', $trimmed)) {
            return $trimmed;
        }

        // Fecha local con separador / o - : acepta uno o dos dígitos en día/mes
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})([ T]\d{1,2}:\d{2}(:\d{2})?)?$#', $trimmed, $m)) {
            $sep    = str_contains($trimmed, '/') ? '/' : '-';
            $hasHms = isset($m[5]) && $m[5] !== '';
            $fmt    = "d{$sep}m{$sep}Y" . (isset($m[4]) && $m[4] !== '' ? ($hasHms ? ' H:i:s' : ' H:i') : '');
            try {
                $carbon = \Carbon\Carbon::createFromFormat($fmt, $trimmed);
                return isset($m[4]) && $m[4] !== ''
                    ? $carbon->format('Y-m-d H:i:s')
                    : $carbon->format('Y-m-d');
            } catch (\Exception $e) {
                return $value;
            }
        }

        return $value;
    }

    private function post(string $path, array $body): ?array
    {
        $this->ensurePhpTimeLimit($this->timeout);

        // Circuit breaker: verificar antes de intentar
        if (!$this->circuitBreaker->isAvailable()) {
            Log::warning('GraphFabricGateway: circuit breaker OPEN, request bloqueado', ['path' => $path]);
            return null;
        }

        try {
            $apiKey = env('GRAPHQL_API_KEY', '');
            $req    = Http::timeout($this->timeout)
                         ->connectTimeout(10)  // Fabric puede tardar en responder
                         ->acceptJson();

            if ($apiKey !== '') {
                $req = $req->withHeaders(['X-API-Key' => $apiKey]);
            }

            $response = $req->post($this->baseUrl . $path, $body);

            // HTTP 422 con "filters_required" → no es un error genérico,
            // es la detección dinámica de vistas pesadas. Propagar al caller.
            if ($response->status() === 422) {
                $data = $response->json();
                if (is_array($data) && ($data['error'] ?? '') === 'filters_required') {
                    // Retornar con flag especial para que queryViewData() lo propague
                    return [
                        '__filters_required' => true,
                        'message'     => $data['message'] ?? 'Esta vista requiere filtros.',
                        'suggestions' => $data['suggestions'] ?? [],
                        'columns'     => $data['columns'] ?? [],
                        'heavy_view'  => true,
                        'schema'      => $data['schema'] ?? null,
                        'view_name'   => $data['view_name'] ?? null,
                    ];
                }
            }

            if ($response->failed()) {
                $status    = $response->status();
                $rawBody   = $response->body();
                $jsonBody  = $response->json();

                // Extraer info útil del body de error
                $detail    = $jsonBody['detail'] ?? $rawBody;
                $errSchema = $jsonBody['schema'] ?? ($body['schema_name'] ?? null);
                $errView   = $jsonBody['view_name'] ?? ($body['view'] ?? null);
                $errType   = $jsonBody['error'] ?? 'unknown';

                // Clasificar el error para el log
                $errorCategory = match (true) {
                    str_contains((string)$detail, 'Invalid column name') => 'INVALID_COLUMN',
                    str_contains((string)$detail, 'Conversion failed')   => 'DATE_CONVERSION',
                    str_contains((string)$detail, 'does not exist')      => 'MISSING_RESOURCE',
                    str_contains((string)$detail, 'more column names')   => 'DDL_ERROR',
                    str_contains((string)$detail, 'timeout')             => 'TIMEOUT',
                    default => 'FABRIC_ERROR',
                };

                Log::error("GraphFabricGateway [{$errorCategory}]", [
                    'path'     => $path,
                    'status'   => $status,
                    'category' => $errorCategory,
                    'schema'   => $errSchema,
                    'view'     => $errView,
                    'detail'   => is_string($detail) ? substr($detail, 0, 300) : json_encode($detail),
                    'error'    => $errType,
                ]);

                // 5xx = problema del servidor Py → registrar fallo
                if ($status >= 500) {
                    $this->circuitBreaker->recordFailure();
                }

                return null;
            }

            // Éxito → resetear circuit breaker
            $this->circuitBreaker->recordSuccess();

            return $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Timeout o conexión rechazada → registrar fallo
            $this->circuitBreaker->recordFailure();
            Log::error('GraphFabricGateway [TIMEOUT]', [
                'path'   => $path,
                'schema' => $body['schema_name'] ?? null,
                'view'   => $body['view'] ?? null,
                'error'  => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            $this->circuitBreaker->recordFailure();
            Log::error('GraphFabricGateway [EXCEPTION]', [
                'path'   => $path,
                'schema' => $body['schema_name'] ?? null,
                'view'   => $body['view'] ?? null,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Evita que max_execution_time de PHP (60s en XAMPP) corte antes que el timeout HTTP.
     */
    private function ensurePhpTimeLimit(int $httpTimeoutSeconds): void
    {
        $limit = max(180, $httpTimeoutSeconds + 10);
        @set_time_limit($limit);
    }
}
