<?php

namespace App\Services\Fabric;

use App\Models\User;
use App\Models\UserGrup;
use App\Models\BiGrupo;
use App\Models\BiVista;
use App\Models\BiVistaDelegacion;
use App\Models\BiVistaDelegacionEsquema;
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
    private int    $catalogTimeout;
    private FabricCircuitBreaker $circuitBreaker;
    private BiVistasSyncService  $vistasSyncService;

    public function __construct()
    {
        $this->baseUrl            = rtrim(config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $this->tokenAdmin         = (string) (config('fabric.token_admin') ?: config('fabric.api_key', ''));
        $this->timeout            = (int) config('fabric.timeout', 185);
        $this->catalogTimeout     = (int) config('fabric.catalog_timeout', 30);
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
        $grupos     = $this->getGruposBdDirectos($user, $tipo);
        $delegados  = $this->getGruposDelegadosPorEmpresa($user, $tipo);
        $porEsquema = $this->getGruposDelegadosPorEsquema($user, $tipo);
        $grupos     = array_values(array_unique(array_merge($grupos, $delegados, $porEsquema)));

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
     * Esquemas origen delegados por esquema: si el usuario tiene AA (destino),
     * también recibe DF (origen) con las vistas configuradas en bi_vista_delegacion_esquemas.
     *
     * @return string[]  Códigos GG-BD-*
     */
    private function getGruposDelegadosPorEsquema(User $user, ?int $tipo = null): array
    {
        if (!Schema::hasTable('bi_vista_delegacion_esquemas')) {
            return [];
        }

        $destinoIds = $this->getDirectGrupoIds($user);
        if ($destinoIds === []) {
            return [];
        }

        $origenIds = BiVistaDelegacionEsquema::query()
            ->whereIn('id_bi_grupos_destino', $destinoIds)
            ->distinct()
            ->pluck('id_bi_grupos_origen')
            ->all();

        if ($origenIds === []) {
            return [];
        }

        $query = BiGrupo::query()->whereIn('id', $origenIds);
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
     * IDs de bi_grupos con acceso directo (users_grups GG-BD-*).
     *
     * @return int[]
     */
    private function getDirectGrupoIds(User $user): array
    {
        $ids = [];
        foreach ($this->getGruposBdDirectos($user) as $codigo) {
            $grupo = $this->resolveBiGrupoByCodigo($codigo);
            if ($grupo !== null) {
                $ids[] = (int) $grupo->id;
            }
        }

        return array_values(array_unique($ids));
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
     *
     * Las vistas delegadas al usuario (bi_vista_delegacion_usuarios) no se
     * restringen por sede: deben verse aunque sean nacionales u otra sede.
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

        $allowed          = array_map('strtolower', $siteContext['site_codes']);
        $known            = $this->knownSiteCodesLower();
        $delegadasUsuario = $this->getUserDelegatedViewNamesSet($user);

        foreach ($response['schemas'] as &$schemaBlock) {
            $schemaBlock['views'] = array_values(array_filter(
                $schemaBlock['views'] ?? [],
                function ($view) use ($allowed, $known, $delegadasUsuario) {
                    $name = strtolower((string) ($view['view_name'] ?? ''));
                    if ($name === '') {
                        return false;
                    }

                    // Delegación explícita al usuario → siempre visible (sin filtro de sede)
                    if (!empty($view['es_delegada']) || isset($delegadasUsuario[$name])) {
                        return true;
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
     * Las vistas delegadas al usuario omiten la restricción de sede.
     * Las vistas de formularios BI (config bi_fabric) solo exigen esquema.
     */
    public function tieneAccesoVistaPorSede(User $user, string $viewName, ?string $schema = null): bool
    {
        if ($this->tieneVistaDelegadaAlUsuario($user, $viewName, $schema)) {
            return true;
        }

        if ($this->tieneVistaDelegadaPorEsquema($user, $viewName, $schema)) {
            return true;
        }

        if ($this->esVistaFormularioSoloEsquema($viewName, $schema)) {
            return true;
        }

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
     * Vistas usadas por formularios BI dedicados (ej. Certificado SOAT).
     * Solo requieren acceso al esquema; no se filtran por sede.
     *
     * @see config/bi_fabric.php
     */
    public function esVistaFormularioSoloEsquema(string $viewName, ?string $schema = null): bool
    {
        $views = config('bi_fabric.vistas_formulario_solo_esquema', []);
        if (!is_array($views) || $views === []) {
            return false;
        }

        $name = strtolower(trim($viewName));
        if ($name === '') {
            return false;
        }

        $qualified = $schema !== null && trim($schema) !== ''
            ? strtolower(trim($schema)) . '.' . $name
            : null;

        foreach ($views as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }

            if ($qualified !== null && $entry === $qualified) {
                return true;
            }

            // Permite coincidencia solo por nombre si el config no trae schema
            if (!str_contains($entry, '.') && $entry === $name) {
                return true;
            }

            // Si no hay schema en la llamada, comparar por el nombre de la vista del config
            if ($qualified === null && str_contains($entry, '.')) {
                $parts = explode('.', $entry, 2);
                if (($parts[1] ?? '') === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Aplica restricciones de departamento definidas en bi_vistas.
     * Ej: VW_AG_Agendas solo para MA y NAL aunque Fabric la muestre a todas las sedes.
     * Las vistas delegadas al usuario no se restringen por departamento/sede.
     */
    private function filterViewsByBiVistasDepartamento(array $response, User $user): array
    {
        if (!isset($response['schemas']) || !is_array($response['schemas']) || !Schema::hasTable('bi_vistas')) {
            return $response;
        }

        $siteContext      = $this->resolveSiteContext($user);
        $siteCodes        = $siteContext['site_codes'];
        $isNational       = $siteContext['is_national'];
        $configIndex      = $this->getBiVistasConfigBySchema();
        $delegadasUsuario = $this->getUserDelegatedViewNamesSet($user);

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
                function ($view) use ($byNombre, $siteCodes, $isNational, $delegadasUsuario) {
                    $nombre = strtolower($view['view_name'] ?? '');

                    if (isset($delegadasUsuario[$nombre])) {
                        return true;
                    }

                    $cfg = $byNombre[$nombre] ?? null;

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
     * Aplica delegación de vistas (empresa / usuario / esquema).
     *
     * - Esquema con acceso directo (users_grups GG-BD-*): no se restringe el
     *   listado; se marcan las vistas delegadas al usuario (aditivo).
     * - Esquema solo por delegación: solo se dejan las vistas del pool
     *   empresa/usuario/esquema y se marcan como delegadas.
     *
     * Las vistas marcadas `es_delegada` omiten el filtro de sede posterior.
     */
    private function filterViewsByDelegacion(array $response, User $user): array
    {
        $hasEmpresaTable = Schema::hasTable('bi_vista_delegaciones');
        $hasEsquemaTable = Schema::hasTable('bi_vista_delegacion_esquemas');

        if (!isset($response['schemas']) || !is_array($response['schemas'])
            || (!$hasEmpresaTable && !$hasEsquemaTable)) {
            return $response;
        }

        $empresaIds = $user->empresas()->pluck('ent_empresas.id')->map(fn ($id) => (int) $id)->all();
        if ($empresaIds === [] && !$hasEsquemaTable) {
            return $response;
        }

        $directSchemas = array_flip(
            collect($this->getGruposBdDirectos($user))
                ->map(fn ($g) => strtolower($this->extractSchema($g)))
                ->filter()
                ->unique()
                ->all()
        );

        $delegacionIndex     = $hasEmpresaTable ? $this->getDelegacionIndex() : [];
        $userDelegacionIndex = $this->getDelegacionUsuarioIndex();
        $esquemaIndex        = $hasEsquemaTable ? $this->getDelegacionEsquemaIndex() : [];
        $directGrupoIds      = $this->getDirectGrupoIds($user);

        foreach ($response['schemas'] as &$schemaBlock) {
            $schema  = strtolower($schemaBlock['schema'] ?? '');
            $grupoId = $this->resolveGrupoIdBySchema($schema);

            if ($grupoId === null) {
                continue;
            }

            $schemaDelegadas = $this->collectSchemaDelegatedNamesForOrigen(
                $grupoId,
                $directGrupoIds,
                $esquemaIndex
            );
            $hasDirect = isset($directSchemas[$schema]);

            if ($hasDirect) {
                // Acceso por esquema: conservar todas; marcar solo las delegadas al usuario
                $userOnlyNames = $empresaIds === []
                    ? []
                    : $this->collectUserDelegatedNamesForSchema(
                        $user,
                        $grupoId,
                        $empresaIds,
                        $userDelegacionIndex
                    );
                $markSet = array_flip(array_merge($userOnlyNames, $schemaDelegadas));

                foreach ($schemaBlock['views'] as &$view) {
                    $nombre = strtolower((string) ($view['view_name'] ?? ''));
                    if ($nombre !== '' && isset($markSet[$nombre])) {
                        $view['es_delegada'] = true;
                    }
                }
                unset($view);

                $schemaBlock['view_count'] = count($schemaBlock['views'] ?? []);
                continue;
            }

            // Solo por delegación: whitelist usuario (si existe) o pool empresa/esquema
            $userOnlyNames = $empresaIds === []
                ? []
                : $this->collectUserDelegatedNamesForSchema(
                    $user,
                    $grupoId,
                    $empresaIds,
                    $userDelegacionIndex
                );

            if ($userOnlyNames !== []) {
                $permitidos = $userOnlyNames;
            } else {
                $empresaNames = $empresaIds === []
                    ? []
                    : $this->collectDelegatedNamesForSchema(
                        $user,
                        $grupoId,
                        $empresaIds,
                        $delegacionIndex,
                        $userDelegacionIndex
                    );
                $permitidos = array_values(array_unique(array_merge($empresaNames, $schemaDelegadas)));
            }

            $permitidosSet = array_flip($permitidos);

            $schemaBlock['views'] = array_values(array_filter(
                $schemaBlock['views'] ?? [],
                function ($view) use ($permitidosSet) {
                    $nombre = strtolower((string) ($view['view_name'] ?? ''));

                    return $nombre !== '' && isset($permitidosSet[$nombre]);
                }
            ));

            foreach ($schemaBlock['views'] as &$view) {
                $view['es_delegada'] = true;
            }
            unset($view);

            $schemaBlock['view_count'] = count($schemaBlock['views']);
        }
        unset($schemaBlock);

        $response['total_views'] = array_sum(
            array_map(fn ($block) => count($block['views'] ?? []), $response['schemas'])
        );

        return $response;
    }

    /**
     * Nombres de vistas delegadas (usuario ∪ empresa) para un esquema.
     *
     * @param  array<string, array<int, string>>  $delegacionIndex
     * @param  array<string, array<int, string>>  $userDelegacionIndex
     * @return string[] lowercase
     */
    private function collectDelegatedNamesForSchema(
        User $user,
        int $grupoId,
        array $empresaIds,
        array $delegacionIndex,
        array $userDelegacionIndex
    ): array {
        $names = [];

        foreach ($empresaIds as $empresaId) {
            $userKey = $user->id . '.' . $empresaId . '.' . $grupoId;
            if (isset($userDelegacionIndex[$userKey])) {
                foreach ($userDelegacionIndex[$userKey] as $nombre) {
                    $names[strtolower($nombre)] = true;
                }
            }

            $key = $empresaId . '.' . $grupoId;
            if (isset($delegacionIndex[$key])) {
                foreach ($delegacionIndex[$key] as $nombre) {
                    $names[strtolower($nombre)] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Solo vistas delegadas explícitamente al usuario (bi_vista_delegacion_usuarios).
     *
     * @param  array<string, array<int, string>>  $userDelegacionIndex
     * @return string[] lowercase
     */
    private function collectUserDelegatedNamesForSchema(
        User $user,
        int $grupoId,
        array $empresaIds,
        array $userDelegacionIndex
    ): array {
        $names = [];

        foreach ($empresaIds as $empresaId) {
            $userKey = $user->id . '.' . $empresaId . '.' . $grupoId;
            if (!isset($userDelegacionIndex[$userKey])) {
                continue;
            }

            foreach ($userDelegacionIndex[$userKey] as $nombre) {
                $names[strtolower($nombre)] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Set lowercase de nombres de vistas delegadas al usuario (todas las empresas).
     *
     * @return array<string, true>
     */
    private function getUserDelegatedViewNamesSet(User $user): array
    {
        if (!Schema::hasTable('bi_vista_delegacion_usuarios')) {
            return [];
        }

        $empresaIds = $user->empresas()->pluck('ent_empresas.id')->map(fn ($id) => (int) $id)->all();
        if ($empresaIds === []) {
            return [];
        }

        $set   = [];
        $index = $this->getDelegacionUsuarioIndex();
        $prefix = $user->id . '.';

        foreach ($index as $key => $nombres) {
            if (!str_starts_with((string) $key, $prefix)) {
                continue;
            }

            // key = user_id.empresa_id.grupo_id
            $parts = explode('.', (string) $key);
            if (count($parts) < 3) {
                continue;
            }

            $empresaId = (int) $parts[1];
            if (!in_array($empresaId, $empresaIds, true)) {
                continue;
            }

            foreach ($nombres as $nombre) {
                $set[strtolower($nombre)] = true;
            }
        }

        return $set;
    }

    /**
     * ¿La vista está en bi_vista_delegacion_usuarios para este usuario?
     */
    private function tieneVistaDelegadaAlUsuario(User $user, string $viewName, ?string $schema = null): bool
    {
        $nombre = strtolower(trim($viewName));
        if ($nombre === '') {
            return false;
        }

        if ($schema !== null && $schema !== '') {
            $empresaIds = $user->empresas()->pluck('ent_empresas.id')->map(fn ($id) => (int) $id)->all();
            if ($empresaIds === []) {
                return false;
            }

            $grupoId = $this->resolveGrupoIdBySchema($schema);
            if ($grupoId === null) {
                return false;
            }

            $names = $this->collectUserDelegatedNamesForSchema(
                $user,
                $grupoId,
                $empresaIds,
                $this->getDelegacionUsuarioIndex()
            );

            return in_array($nombre, $names, true);
        }

        return isset($this->getUserDelegatedViewNamesSet($user)[$nombre]);
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
     * @return array<string, array<int, string>>  "destino_grupo_id.origen_grupo_id" => [nombre_vista, ...]
     */
    private function getDelegacionEsquemaIndex(): array
    {
        if (!Schema::hasTable('bi_vista_delegacion_esquemas')) {
            return [];
        }

        return Cache::remember('bi_vista_delegacion_esquemas_index', 300, function () {
            $index = [];

            BiVistaDelegacionEsquema::query()
                ->with(['vista:id,nombre'])
                ->get(['id_bi_grupos_destino', 'id_bi_grupos_origen', 'id_bi_vista'])
                ->each(function (BiVistaDelegacionEsquema $row) use (&$index) {
                    $nombre = $row->vista?->nombre;
                    if ($nombre === null) {
                        return;
                    }

                    $key           = $row->id_bi_grupos_destino . '.' . $row->id_bi_grupos_origen;
                    $index[$key][] = $nombre;
                });

            return $index;
        });
    }

    /**
     * Vistas del esquema origen delegadas a alguno de los esquemas destino del usuario.
     *
     * @param  int[]  $directGrupoIds
     * @param  array<string, array<int, string>>  $esquemaIndex
     * @return string[] lowercase
     */
    private function collectSchemaDelegatedNamesForOrigen(
        int $origenGrupoId,
        array $directGrupoIds,
        array $esquemaIndex
    ): array {
        $names = [];

        foreach ($directGrupoIds as $destinoId) {
            $key = $destinoId . '.' . $origenGrupoId;
            foreach ($esquemaIndex[$key] ?? [] as $nombre) {
                $names[strtolower($nombre)] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * ¿La vista está delegada por esquema a algún GG-BD-* directo del usuario?
     */
    private function tieneVistaDelegadaPorEsquema(User $user, string $viewName, ?string $schema = null): bool
    {
        if (!Schema::hasTable('bi_vista_delegacion_esquemas')) {
            return false;
        }

        $nombre = strtolower(trim($viewName));
        if ($nombre === '') {
            return false;
        }

        $directGrupoIds = $this->getDirectGrupoIds($user);
        if ($directGrupoIds === []) {
            return false;
        }

        $index = $this->getDelegacionEsquemaIndex();

        if ($schema !== null && trim($schema) !== '') {
            $origenGrupoId = $this->resolveGrupoIdBySchema($schema);
            if ($origenGrupoId === null) {
                return false;
            }

            $names = $this->collectSchemaDelegatedNamesForOrigen(
                $origenGrupoId,
                $directGrupoIds,
                $index
            );

            return in_array($nombre, $names, true);
        }

        // Sin schema: buscar el nombre en cualquier origen delegado a los destinos del usuario
        foreach ($index as $key => $nombres) {
            $parts = explode('.', (string) $key, 2);
            if (count($parts) < 2) {
                continue;
            }
            $destinoId = (int) $parts[0];
            if (!in_array($destinoId, $directGrupoIds, true)) {
                continue;
            }
            foreach ($nombres as $n) {
                if (strtolower($n) === $nombre) {
                    return true;
                }
            }
        }

        return false;
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

        // Si hay vistas delegadas de otra sede, el GRANT debe ir como NAL
        // (mismo patrón que multi-sede); Laravel recorta después.
        $grantDepartment = $this->resolveDepartmentForGrantView($user);

        $schemaKey = $schema ? strtolower($schema) : 'all';
        $tipoKey   = $tipo ?? 'all';
        $cacheKey  = sprintf(
            'fabric_views:%d:%s:%s:%s',
            $user->id,
            $schemaKey,
            $tipoKey,
            md5(($grantDepartment ?? '') . ($departamento ?? '') . implode(',', $siteCodes) . implode(',', $grupos))
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

            $response = $this->post('/api/catalog/views', $payload, $this->catalogTimeout);

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
            ), $this->catalogTimeout);

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

        if (!$this->tieneAccesoVistaPorSede($user, $viewName, $schema)) {
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

        if (!$this->tieneAccesoVistaPorSede($user, $view, $schema)) {
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
        $cacheTtl = (int) config('fabric.query_cache_ttl', 30);

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

        if (!$this->tieneAccesoVistaPorSede($user, $view, $schema)) {
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
                // Columnas que deben preservar ceros iniciales (texto, no número)
                'text_columns' => $options['text_columns'] ?? [],
            ]
        );

        try {
            $exportTimeout = max($this->timeout, 300);
            $this->ensurePhpTimeLimit($exportTimeout);
            $apiKey = config('fabric.api_key', '');
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
            'user_email'  => config('fabric.admin_email', 'sistema@medilaser.com.co'),
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

        if (!$this->tieneAccesoVistaPorSede($user, $view, $schema)) {
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
     *
     * Si el usuario tiene vistas delegadas (otra sede / nacionales), se envía
     * NAL para que Graph-Fabric no las elimine antes de que Laravel aplique
     * el filtro de sede + bypass de delegación.
     */
    public function resolveDepartmentForGrantView(User $user, ?string $viewName = null): ?string
    {
        $siteContext = $this->resolveSiteContext($user);

        if ($siteContext['is_national']) {
            return 'NAL';
        }

        // Vista específicamente delegada al usuario → no restringir por sede en GRANT
        if ($viewName !== null && trim($viewName) !== ''
            && $this->tieneVistaDelegadaAlUsuario($user, $viewName)) {
            return 'NAL';
        }

        // Vista delegada por esquema (ej. AA → vista de DF) → GRANT nacional
        if ($viewName !== null && trim($viewName) !== ''
            && $this->tieneVistaDelegadaPorEsquema($user, $viewName)) {
            return 'NAL';
        }

        // Formularios BI con vista nacional → GRANT como nacional (solo valida esquema)
        if ($viewName !== null && trim($viewName) !== ''
            && $this->esVistaFormularioSoloEsquema($viewName)) {
            return 'NAL';
        }

        $codes = $siteContext['site_codes'];
        if ($codes === []) {
            return $siteContext['department'];
        }

        // Listado de catálogo: con delegaciones hay que traer todo el esquema
        if ($viewName === null || trim($viewName) === '') {
            if ($this->usuarioTieneVistasDelegadas($user)) {
                return 'NAL';
            }

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
        if (count($codes) > 1 || $this->usuarioTieneVistasDelegadas($user)) {
            return 'NAL';
        }

        return $codes[0];
    }

    /** ¿Tiene al menos una vista en bi_vista_delegacion_usuarios? */
    private function usuarioTieneVistasDelegadas(User $user): bool
    {
        return $this->getUserDelegatedViewNamesSet($user) !== [];
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
        // Python soporta: array [from, to] → BETWEEN, y "from..to" string → BETWEEN
        if (str_contains($trimmed, '..') && preg_match('#^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$#', $trimmed, $m)) {
            $from = $m[1];
            $to   = $m[2];
            // Para BETWEEN con datetime2: el "to" debe incluir fin del día
            // BETWEEN '2026-07-16' AND '2026-07-16 23:59:59' incluye todo el día
            return ["{$from} 00:00:00", "{$to} 23:59:59"];
        }

        // Operadores de comparación (>=, <=, >, <) — Python los soporta en string
        if (preg_match('#^([><!]=?)(\d{4}-\d{2}-\d{2}.*)$#', $trimmed)) {
            return $trimmed;
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

    private function post(string $path, array $body, ?int $timeoutOverride = null): ?array
    {
        $timeout = $timeoutOverride ?? $this->timeout;
        $this->ensurePhpTimeLimit($timeout);

        // Circuit breaker: verificar antes de intentar
        if (!$this->circuitBreaker->isAvailable()) {
            Log::warning('GraphFabricGateway: circuit breaker OPEN, request bloqueado', ['path' => $path]);
            return null;
        }

        try {
            $apiKey = config('fabric.api_key', '');
            $req    = Http::timeout($timeout)
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

                // Registrar en tabla de errores BI para monitoreo y auto-mantenimiento
                if ($errSchema && $errView) {
                    try {
                        \App\Models\BiVistaErrorLog::registrar(
                            schema: $errSchema,
                            view: $errView,
                            errorType: $errorCategory === 'TIMEOUT' ? \App\Models\BiVistaErrorLog::TYPE_TIMEOUT : \App\Models\BiVistaErrorLog::TYPE_FABRIC_ERROR,
                            message: is_string($detail) ? substr($detail, 0, 500) : ($errType ?? 'Error desconocido'),
                            detail: is_string($detail) ? $detail : json_encode($detail),
                            userEmail: $body['user_email'] ?? null,
                            department: $body['department'] ?? null,
                            category: $errorCategory,
                        );
                    } catch (\Throwable $logErr) {
                        // No interrumpir el flujo si falla el log
                    }
                }

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

            // Registrar timeout en tabla de errores BI
            $tSchema = $body['schema_name'] ?? null;
            $tView = $body['view'] ?? null;
            if ($tSchema && $tView) {
                try {
                    \App\Models\BiVistaErrorLog::registrar(
                        schema: $tSchema,
                        view: $tView,
                        errorType: \App\Models\BiVistaErrorLog::TYPE_TIMEOUT,
                        message: 'Timeout: ' . substr($e->getMessage(), 0, 200),
                        detail: $e->getMessage(),
                        userEmail: $body['user_email'] ?? null,
                        department: $body['department'] ?? null,
                        elapsedMs: $timeout * 1000,
                        category: 'TIMEOUT',
                    );
                } catch (\Throwable $logErr) {
                    // No interrumpir
                }
            }

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

    // =========================================================================
    // PAGINACIÓN OPTIMIZADA PARA VISTAS GRANDES
    // =========================================================================

    /**
     * Obtener datos paginados con cursor para interfaz Excel-like.
     * Optimizado para vistas grandes (100K+ filas) con virtual scrolling.
     *
     * @param User $user
     * @param string $schema
     * @param string $view
     * @param array $options [cursor, limit, filters, sorts]
     * @return array{success: bool, data: array, has_more: bool, total: int, message: ?string, code?: int}
     */
    public function getDataPaginated(User $user, string $schema, string $view, array $options = []): array
    {
        if (!$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success'  => false,
                'data'     => [],
                'has_more' => false,
                'total'    => 0,
                'message'  => "Sin acceso al esquema '{$schema}'.",
                'code'     => 403,
            ];
        }

        if (!$this->tieneAccesoVistaPorSede($user, $view, $schema)) {
            return [
                'success'  => false,
                'data'     => [],
                'has_more' => false,
                'total'    => 0,
                'message'  => "Sin acceso a la vista '{$view}' por sede.",
                'code'     => 403,
            ];
        }

        $cursor  = $options['cursor'] ?? 0;
        $limit   = min((int)($options['limit'] ?? 100), 1000);
        $filters = $options['filters'] ?? [];
        $sorts   = $options['sorts'] ?? [];

        // Construir filtros para la API Python
        $fabricFilters = $this->buildFabricFilters($filters);
        $fabricSorts   = $this->buildFabricSorts($sorts);

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
                'offset'      => $cursor,
                'limit'       => $limit,
                'filters'     => $fabricFilters,
                'sorts'       => $fabricSorts,
            ]
        );

        try {
            $timeout = max($this->timeout, 60);
            $this->ensurePhpTimeLimit($timeout);
            $apiKey = config('fabric.api_key', '');
            $req    = Http::timeout($timeout)
                         ->connectTimeout(10)
                         ->acceptJson();

            if ($apiKey !== '') {
                $req = $req->withHeaders(['X-API-Key' => $apiKey]);
            }

            $response = $req->post($this->baseUrl . '/api/data/paginated', $payload);

            if ($response->failed()) {
                Log::error('GraphFabricGateway getDataPaginated error', [
                    'status' => $response->status(),
                    'schema' => $schema,
                    'view'   => $view,
                    'cursor' => $cursor,
                    'limit'  => $limit,
                ]);

                return [
                    'success'  => false,
                    'data'     => [],
                    'has_more' => false,
                    'total'    => 0,
                    'message'  => "Error obteniendo datos paginados: HTTP {$response->status()}",
                    'code'     => $response->status(),
                ];
            }

            $result = $response->json();

            return [
                'success'  => true,
                'data'     => $result['data'] ?? [],
                'has_more' => $result['has_more'] ?? false,
                'total'    => $result['total'] ?? 0,
                'message'  => null,
            ];
        } catch (\Exception $e) {
            Log::error('GraphFabricGateway getDataPaginated exception', [
                'error'  => $e->getMessage(),
                'schema' => $schema,
                'view'   => $view,
            ]);

            return [
                'success'  => false,
                'data'     => [],
                'has_more' => false,
                'total'    => 0,
                'message'  => 'Error obteniendo datos paginados: ' . $e->getMessage(),
                'code'     => 500,
            ];
        }
    }

    /**
     * Estimar el número de filas en una vista (para decidir estrategia de carga).
     *
     * @param User $user
     * @param string $schema
     * @param string $view
     * @return array{success: bool, count: int, message: ?string, code?: int}
     */
    public function estimateRowCount(User $user, string $schema, string $view): array
    {
        if (!$this->tieneAccesoEsquema($user, $schema)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => "Sin acceso al esquema '{$schema}'.",
                'code'    => 403,
            ];
        }

        if (!$this->tieneAccesoVistaPorSede($user, $view, $schema)) {
            return [
                'success' => false,
                'count'   => 0,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
                'code'    => 403,
            ];
        }

        // Cache por 15 minutos
        $cacheKey = "vista_row_count_{$schema}_{$view}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return [
                'success' => true,
                'count'   => $cached,
                'message' => null,
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
            ]
        );

        try {
            $apiKey = config('fabric.api_key', '');
            $req    = Http::timeout(30)
                         ->connectTimeout(10)
                         ->acceptJson();

            if ($apiKey !== '') {
                $req = $req->withHeaders(['X-API-Key' => $apiKey]);
            }

            $response = $req->post($this->baseUrl . '/api/data/estimate-rows', $payload);

            if ($response->failed()) {
                Log::error('GraphFabricGateway estimateRowCount error', [
                    'status' => $response->status(),
                    'schema' => $schema,
                    'view'   => $view,
                ]);

                return [
                    'success' => false,
                    'count'   => 0,
                    'message' => "Error estimando filas: HTTP {$response->status()}",
                    'code'    => $response->status(),
                ];
            }

            $result = $response->json();
            $count  = $result['count'] ?? 0;

            // Cache por 15 minutos
            Cache::put($cacheKey, $count, now()->addMinutes(15));

            return [
                'success' => true,
                'count'   => $count,
                'message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('GraphFabricGateway estimateRowCount exception', [
                'error'  => $e->getMessage(),
                'schema' => $schema,
                'view'   => $view,
            ]);

            return [
                'success' => false,
                'count'   => 0,
                'message' => 'Error estimando filas: ' . $e->getMessage(),
                'code'    => 500,
            ];
        }
    }

    /**
     * Construir filtros en formato esperado por la API Python.
     *
     * @param array $filters [['field' => 'Edad', 'operator' => 'gt', 'value' => 18], ...]
     * @return array Filtros normalizados
     */
    private function buildFabricFilters(array $filters): array
    {
        $fabricFilters = [];

        foreach ($filters as $filter) {
            $field    = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? 'equals';
            $value    = $filter['value'] ?? null;

            if (!$field || $value === null) {
                continue;
            }

            // Normalizar valor (fechas, etc.)
            $normalizedValue = $this->normalizeFilterValue($value);

            // Mapear operador a formato SQL
            $sqlOperator = match($operator) {
                'contains'   => 'LIKE',
                'equals'     => '=',
                'notEquals'  => '!=',
                'gt'         => '>',
                'gte'        => '>=',
                'lt'         => '<',
                'lte'        => '<=',
                'between'    => 'BETWEEN',
                'in'         => 'IN',
                default      => '='
            };

            // Construir filtro según operador
            if ($operator === 'contains' && is_string($normalizedValue)) {
                $fabricFilters[$field] = "%{$normalizedValue}%";
            } elseif ($operator === 'between' && is_array($normalizedValue) && count($normalizedValue) === 2) {
                $fabricFilters[$field] = ['BETWEEN', $normalizedValue[0], $normalizedValue[1]];
            } elseif ($operator === 'in' && is_array($normalizedValue)) {
                $fabricFilters[$field] = ['IN', $normalizedValue];
            } else {
                $fabricFilters[$field] = $normalizedValue;
            }
        }

        return $fabricFilters;
    }

    /**
     * Construir ordenamientos en formato esperado por la API Python.
     *
     * @param array $sorts [['field' => 'FechaNacimiento', 'direction' => 'desc'], ...]
     * @return array [[field, direction], ...]
     */
    private function buildFabricSorts(array $sorts): array
    {
        $fabricSorts = [];

        foreach ($sorts as $sort) {
            $field     = $sort['field'] ?? null;
            $direction = strtoupper($sort['direction'] ?? 'ASC');

            if (!$field || !in_array($direction, ['ASC', 'DESC'])) {
                continue;
            }

            $fabricSorts[] = [$field, $direction];
        }

        return $fabricSorts;
    }
}
