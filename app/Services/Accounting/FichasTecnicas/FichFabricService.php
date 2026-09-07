<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\User;
use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\ODataParquetService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lectura de CUPS, homólogos y profesionales desde Microsoft Fabric.
 *
 * Reemplaza las tablas locales pesadas del legacy (`fich_cups` con ~29k filas,
 * `fich_homologos` con ~15k, `fich_profesionales` con ~1k) por lectura en vivo
 * de las vistas de Fabric, siempre actualizadas y sin duplicar el maestro:
 *
 *   df.VW_Contract_CUPS            → todos los CUPS
 *   df.VW_Contract_CUPS_Homologos  → CUPS homólogos (ISS/SOAT/institucional)
 *   dc.VW_AD_ProfesionalesSinFirma → profesionales
 *
 * Sigue el mismo patrón que ActivoFijoService: intenta filtrar sobre el parquet
 * local (DuckDB, ~90 ms) y cae a la vista SQL en vivo si el parquet no está
 * disponible. Normaliza las columnas a un contrato estable para el frontend, de
 * modo que un cambio de casing/nombre en Fabric no rompa la UI.
 */
final class FichFabricService
{
    // ── Vistas de Fabric ─────────────────────────────────────────────────
    private const CUPS_SCHEMA = 'df';
    private const CUPS_VIEW    = 'VW_Contract_CUPS';

    private const HOMOLOGOS_SCHEMA = 'df';
    private const HOMOLOGOS_VIEW   = 'VW_Contract_CUPS_Homologos';

    private const CUPS_GRUPOS_TTL = 86400; // 24 h — catálogos normativos

    private const PROF_SCHEMA = 'dc';
    private const PROF_VIEW   = 'VW_AD_Profesionales'; // 19 cols — confirmado 2026-09-03

    private const CACHE_TTL = 600; // 10 min

    public function __construct(
        private readonly GraphFabricGatewayService $gateway,
        private readonly ODataParquetService $parquet,
    ) {
    }

    // =========================================================================
    // CUPS
    // =========================================================================

    /**
     * Busca CUPS por código o descripción.
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string, code?: int}
     */
    public function buscarCups(User $user, string $termino, int $limit = 50): array
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return ['success' => true, 'data' => [], 'total' => 0];
        }

        // Un término numérico busca por código (parcial, para autocompletar);
        // texto busca por descripción.
        $columna = ctype_digit($termino) ? 'Code' : 'Description';

        $filas = $this->consultar(
            self::CUPS_SCHEMA,
            self::CUPS_VIEW,
            $user,
            [$columna => "%{$termino}%"],
            $limit,
        );

        if ($filas === null) {
            return ['success' => false, 'message' => 'No se pudo consultar el maestro de CUPS.', 'code' => 502];
        }

        $data = array_map(fn (array $f): array => $this->normalizarCups($f), $filas);

        return ['success' => true, 'data' => $data, 'total' => count($data)];
    }

    /**
     * Busca homólogos de un CUPS (por código CUPS o descripción del servicio).
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string, code?: int}
     */
    public function buscarHomologos(User $user, string $termino, ?string $tipoManual = null, int $limit = 50): array
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return ['success' => true, 'data' => [], 'total' => 0];
        }

        $columna = ctype_digit($termino) ? 'CUPS' : 'Descripcion';
        $filtros = [$columna => "%{$termino}%"];

        if ($tipoManual !== null && trim($tipoManual) !== '') {
            $filtros['Tipo_Manual'] = trim($tipoManual);
        }

        $filas = $this->consultar(self::HOMOLOGOS_SCHEMA, self::HOMOLOGOS_VIEW, $user, $filtros, $limit);

        if ($filas === null) {
            return ['success' => false, 'message' => 'No se pudo consultar el maestro de homólogos.', 'code' => 502];
        }

        $data = array_map(fn (array $f): array => $this->normalizarHomologo($f), $filas);

        return ['success' => true, 'data' => $data, 'total' => count($data)];
    }

    /**
     * Homólogos de un CUPS concreto (cascada del paso 2 del generador).
     * Filtra df.VW_Contract_CUPS_Homologos por la columna CUPS.
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string}
     */
    public function homologosDeCups(User $user, string $codeCups): array
    {
        $codeCups = trim($codeCups);

        if ($codeCups === '') {
            return ['success' => true, 'data' => [], 'total' => 0];
        }

        $cacheKey = "fich_fabric:homologos:{$codeCups}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $filas = $this->consultar(self::HOMOLOGOS_SCHEMA, self::HOMOLOGOS_VIEW, $user, ['CUPS' => $codeCups], 50);

        if ($filas === null) {
            return ['success' => false, 'data' => [], 'total' => 0, 'message' => 'No se pudo consultar homólogos del CUPS.'];
        }

        $result = [
            'success' => true,
            'data'    => array_map(fn (array $f): array => $this->normalizarHomologo($f), $filas),
            'total'   => count($filas),
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    // =========================================================================
    // Profesionales
    // =========================================================================

    /**
     * Busca profesionales por código, identificación, nombre o especialidad.
     *
     * VW_AD_Profesionales (19 cols):
     *   SOURCE, Codigo, Identificacion, Medico, Direccion, Telefono, Celular,
     *   TarjetaProfesional, Especialidad1, Especialidad2, Especialidad3, Firma,
     *   Correo, Sucursal, EstadoProfesional, EstadoUsuario, Perfil, Profesion,
     *   UsuarioRelacionado
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string, code?: int}
     */
    public function buscarProfesionales(User $user, string $termino, int $limit = 50, ?string $especialidad = null): array
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return ['success' => true, 'data' => [], 'total' => 0];
        }

        // Codigo en la vista son dígitos con espacios (e.g. "013                 ")
        // Si el término es numérico corto, busca por Codigo o Identificacion;
        // de lo contrario busca por nombre (Medico).
        if (ctype_digit($termino) && mb_strlen($termino) <= 6) {
            $filtros = ['Codigo' => "%{$termino}%"];
        } elseif (ctype_digit($termino)) {
            $filtros = ['Identificacion' => "%{$termino}%"];
        } else {
            $filtros = ['Medico' => "%{$termino}%"];
        }

        // Filtro adicional por especialidad (Especialidad1) si se provee
        if ($especialidad !== null && trim($especialidad) !== '') {
            $filtros['Especialidad1'] = "%{$especialidad}%";
        }

        // Solo traer profesionales activos
        $filtros['EstadoProfesional'] = 'Activo';

        $filas = $this->consultar(
            self::PROF_SCHEMA,
            self::PROF_VIEW,
            $user,
            $filtros,
            $limit,
        );

        if ($filas === null) {
            return ['success' => false, 'message' => 'No se pudo consultar el maestro de profesionales.', 'code' => 502];
        }

        $data = array_map(fn (array $f): array => $this->normalizarProfesional($f), $filas);

        return ['success' => true, 'data' => $data, 'total' => count($data)];
    }

    /**
     * Lista profesionales por especialidad exacta (para el selector del paso-datos).
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string, code?: int}
     */
    public function profesionalesPorEspecialidad(User $user, string $especialidad, int $limit = 100): array
    {
        $especialidad = trim($especialidad);

        if ($especialidad === '') {
            return ['success' => true, 'data' => [], 'total' => 0];
        }

        $cacheKey = "fich_fabric:profs_esp:".md5($especialidad);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $filas = $this->consultar(self::PROF_SCHEMA, self::PROF_VIEW, $user, [
            'Especialidad1'        => "%{$especialidad}%",
            'EstadoProfesional'    => 'Activo',
        ], $limit);

        if ($filas === null) {
            return ['success' => false, 'message' => 'No se pudo consultar profesionales por especialidad.', 'code' => 502];
        }

        $result = [
            'success' => true,
            'data'    => array_map(fn (array $f): array => $this->normalizarProfesional($f), $filas),
            'total'   => count($filas),
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Resuelve un profesional por su código exacto (para validar al guardar la ficha).
     *
     * @return array<string, mixed>|null
     */
    public function profesionalPorCodigo(User $user, string $codigo): ?array
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return null;
        }

        $cacheKey = "fich_fabric:prof:{$codigo}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        // El código en Fabric tiene trailing spaces, buscamos por LIKE
        $filas = $this->consultar(self::PROF_SCHEMA, self::PROF_VIEW, $user, ['Codigo' => "{$codigo}%"], 5);

        if ($filas === null || $filas === []) {
            return null;
        }

        // Matchear exacto (trim)
        $match = null;
        foreach ($filas as $fila) {
            $i  = $this->indice($fila);
            $cod = trim((string) ($this->val($i, ['Codigo']) ?? ''));
            if ($cod === $codigo) {
                $match = $fila;
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        $prof = $this->normalizarProfesional($match);
        Cache::put($cacheKey, $prof, self::CACHE_TTL);

        return $prof;
    }

    // =========================================================================
    // Motor de consulta (parquet con fallback a vista SQL)
    // =========================================================================

    /**
     * Consulta una vista de Fabric filtrando por parquet local; si no está
     * disponible, cae a la vista SQL en vivo. Devuelve las filas crudas o null.
     *
     * @param  array<string, string>  $filtros
     * @return list<array<string, mixed>>|null
     */
    private function consultar(string $schema, string $view, User $user, array $filtros, int $limit): ?array
    {
        // ── Camino rápido: parquet-filter (DuckDB) ───────────────────────
        if (config('fabric.fichas_parquet', true)) {
            try {
                $res = $this->parquet->filter($schema, $view, $filtros, $limit, 0, ['count' => false]);

                if ($res['success'] ?? false) {
                    return $res['value'] ?? [];
                }
            } catch (\Throwable $e) {
                Log::warning('[FichFabric] parquet-filter falló, usando vista SQL', [
                    'view'  => "{$schema}.{$view}",
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Fallback: vista SQL en vivo vía /api/data/dynamic ────────────
        $res = $this->gateway->queryViewData($user, $schema, $view, [
            'columns'    => [],
            'filters'    => $filtros,
            'limit'      => $limit,
            'offset'     => 0,
            'skip_count' => true,
        ]);

        if (! ($res['success'] ?? false)) {
            return null;
        }

        return $res['data'] ?? [];
    }

    // =========================================================================
    // Normalización a contrato estable (insensible a casing/separadores)
    // =========================================================================

    /**
     * Normaliza una fila de df.VW_Contract_CUPS.
     *
     * Además del contrato original (code/descripcion/…), expone alias
     * `subcategoria`/`desc_subcat` y `grupo`/`subgrupo` para que el frontend
     * del generador (paso 2) los consuma igual que el legacy cups_2641.
     *
     * @param array<string, mixed> $f  @return array<string, mixed>
     */
    private function normalizarCups(array $f): array
    {
        $i    = $this->indice($f);
        $code = $this->val($i, ['Code']);
        $desc = $this->val($i, ['Description']);

        return [
            'code'             => $code,
            'descripcion'      => $desc,
            // Alias que espera el autocomplete del paso 2 (contrato cups_2641)
            'subcategoria'     => $code,
            'desc_subcat'      => $desc,
            // Grupo de facturación (CodGF/Grupo_Fact) y concepto (subgrupo)
            'grupo'            => $this->val($i, ['CodGF']),
            'desc_grup'        => $this->val($i, ['Grupo_Fact', 'GrupoFact']),
            'subgrupo'         => $this->val($i, ['CodigoConcepto']),
            'desc_subg'        => $this->val($i, ['Concepto_Fact', 'ConceptoFact']),
            // Campos originales
            'cod_rips'         => $this->val($i, ['CodRIPS']),
            'rips'             => $this->val($i, ['RIPS']),
            'codigo_concepto'  => $this->val($i, ['CodigoConcepto']),
            'concepto_fact'    => $this->val($i, ['Concepto_Fact', 'ConceptoFact']),
            'grupo_fact'       => $this->val($i, ['Grupo_Fact', 'GrupoFact']),
            'estado'           => $this->val($i, ['Estado']),
            'financia_upc'     => $this->val($i, ['Financia_UPC', 'FinanciaUPC']),
        ];
    }

    // =========================================================================
    // Grupos y subgrupos de CUPS (desde df.VW_Contract_CUPS, cacheados)
    // =========================================================================

    /**
     * Grupos de facturación distintos (CodGF + Grupo_Fact).
     * Reemplaza ajax/get_grupos.php (SELECT DISTINCT grupo, desc_grup FROM cups_2641).
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string}
     */
    public function gruposCups(User $user): array
    {
        $cacheKey = 'fich_fabric:cups_grupos';
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        // Traer un lote amplio y agrupar en PHP (Fabric no soporta DISTINCT vía gateway)
        $filas = $this->consultar(self::CUPS_SCHEMA, self::CUPS_VIEW, $user, [], 10000);

        if ($filas === null) {
            return ['success' => false, 'data' => [], 'total' => 0, 'message' => 'No se pudo consultar grupos CUPS.'];
        }

        $grupos = [];
        foreach ($filas as $fila) {
            $i    = $this->indice($fila);
            $cod  = trim((string) ($this->val($i, ['CodGF']) ?? ''));
            $desc = trim((string) ($this->val($i, ['Grupo_Fact', 'GrupoFact']) ?? ''));
            if ($cod === '' || isset($grupos[$cod])) {
                continue;
            }
            $grupos[$cod] = ['grupo' => $cod, 'desc_grup' => $cod.' - '.$desc];
        }

        ksort($grupos);
        $result = ['success' => true, 'data' => array_values($grupos), 'total' => count($grupos)];
        Cache::put($cacheKey, $result, self::CUPS_GRUPOS_TTL);

        return $result;
    }

    /**
     * Subgrupos (conceptos de facturación) distintos (CodigoConcepto + Concepto_Fact).
     * Reemplaza ajax/get_subgrupos.php.
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string}
     */
    public function subgruposCups(User $user): array
    {
        $cacheKey = 'fich_fabric:cups_subgrupos';
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $filas = $this->consultar(self::CUPS_SCHEMA, self::CUPS_VIEW, $user, [], 10000);

        if ($filas === null) {
            return ['success' => false, 'data' => [], 'total' => 0, 'message' => 'No se pudo consultar subgrupos CUPS.'];
        }

        $subgrupos = [];
        foreach ($filas as $fila) {
            $i    = $this->indice($fila);
            $cod  = trim((string) ($this->val($i, ['CodigoConcepto']) ?? ''));
            $desc = trim((string) ($this->val($i, ['Concepto_Fact', 'ConceptoFact']) ?? ''));
            if ($cod === '' || isset($subgrupos[$cod])) {
                continue;
            }
            $subgrupos[$cod] = ['subgrupo' => $cod, 'desc_subg' => $cod.' - '.$desc];
        }

        ksort($subgrupos);
        $result = ['success' => true, 'data' => array_values($subgrupos), 'total' => count($subgrupos)];
        Cache::put($cacheKey, $result, self::CUPS_GRUPOS_TTL);

        return $result;
    }

    /**
     * Normaliza una fila de df.VW_Contract_CUPS_Homologos.
     *
     * Expone alias `code_manual`/`desc_manual` (contrato legacy homologos) para
     * que el select de homologación del paso 2 los consuma sin cambios.
     *
     * @param array<string, mixed> $f  @return array<string, mixed>
     */
    private function normalizarHomologo(array $f): array
    {
        $i         = $this->indice($f);
        $codServ   = $this->val($i, ['CodServicioIPS']);
        $descServ  = $this->val($i, ['DescripcionServicio']);

        return [
            'cups'                  => $this->val($i, ['CUPS']),
            'descripcion'           => $this->val($i, ['Descripcion', 'Descripción']),
            // Alias contrato legacy: code_manual/desc_manual
            'code_manual'           => $codServ,
            'desc_manual'           => $descServ,
            'code_cups'             => $this->val($i, ['CUPS']),
            'desc_cups'             => $this->val($i, ['Descripcion', 'Descripción']),
            'valor'                 => null, // Fabric no trae tarifa monetaria directa
            // Campos originales
            'cod_servicio_ips'      => $codServ,
            'descripcion_servicio'  => $descServ,
            'tipo_manual'           => $this->val($i, ['Tipo_Manual', 'TipoManual']),
            'concepto_facturacion'  => $this->val($i, ['Concepto_Facturacion', 'ConceptoFacturacion']),
            'uvr_grupo_qx'          => $this->val($i, ['UVR_Grupo_Qx', 'UVRGrupoQx']),
            'grupo_facturacion'     => $this->val($i, ['GrupoFacturacion']),
        ];
    }

    /**
     * Normaliza una fila de dc.VW_AD_Profesionales (19 columnas) a contrato estable.
     *
     * Columnas de origen:
     *   SOURCE, Codigo, Identificacion, Medico, Direccion, Telefono, Celular,
     *   TarjetaProfesional, Especialidad1, Especialidad2, Especialidad3, Firma,
     *   Correo, Sucursal, EstadoProfesional, EstadoUsuario, Perfil, Profesion,
     *   UsuarioRelacionado
     *
     * @param array<string, mixed> $f  @return array<string, mixed>
     */
    private function normalizarProfesional(array $f): array
    {
        $i = $this->indice($f);

        // Especialidades: combinar las 3 en lista para filtrado por frontend
        $esps = array_filter(array_map(
            fn ($k) => $this->val($i, [$k]),
            ['Especialidad1', 'Especialidad2', 'Especialidad3']
        ));

        return [
            // Clave de negocio: código Fabric (trimmed) — se guarda en fich_fichas
            'codigo'              => trim((string) ($this->val($i, ['Codigo']) ?? '')),
            // Datos personales / identificación
            'nombre'              => trim((string) ($this->val($i, ['Medico']) ?? '')),
            'identificacion'      => trim((string) ($this->val($i, ['Identificacion']) ?? '')),
            'tarjeta_profesional' => trim((string) ($this->val($i, ['TarjetaProfesional']) ?? '')),
            // Contacto
            'correo'              => $this->val($i, ['Correo']),
            'telefono'            => $this->val($i, ['Telefono']),
            'celular'             => $this->val($i, ['Celular']),
            'direccion'           => $this->val($i, ['Direccion', 'Dirección']),
            // Ubicación / contexto Fabric
            'sucursal_sede'       => $this->val($i, ['Sucursal']),
            'source'              => $this->val($i, ['SOURCE']),
            // Especialidades
            'especialidad'        => $this->val($i, ['Especialidad1']),   // principal
            'especialidades'      => array_values($esps),                  // todas
            // Clasificación
            'profesion'           => $this->val($i, ['Profesion', 'Profesión']),
            'perfil'              => $this->val($i, ['Perfil']),
            // Estado
            'estado_profesional'  => $this->val($i, ['EstadoProfesional']),
            'estado_usuario'      => $this->val($i, ['EstadoUsuario']),
            'tiene_firma'         => strtolower((string) ($this->val($i, ['Firma']) ?? '')) === 'si',
            // Referencia cruzada
            'usuario_relacionado' => trim((string) ($this->val($i, ['UsuarioRelacionado']) ?? '')),
        ];
    }

    /**
     * Índice de la fila insensible a casing y separadores:
     * "Concepto_Fact" → "conceptofact".
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function indice(array $fila): array
    {
        $indice = [];
        foreach ($fila as $clave => $valor) {
            $indice[mb_strtolower(str_replace(['_', ' ', '-'], '', (string) $clave))] = $valor;
        }

        return $indice;
    }

    /**
     * Primer candidato con valor en el índice normalizado.
     *
     * @param  array<string, mixed>  $indice
     * @param  list<string>  $candidatos
     */
    private function val(array $indice, array $candidatos): ?string
    {
        foreach ($candidatos as $candidato) {
            $clave = mb_strtolower(str_replace(['_', ' ', '-'], '', $candidato));
            if (array_key_exists($clave, $indice) && $indice[$clave] !== null && $indice[$clave] !== '') {
                return (string) $indice[$clave];
            }
        }

        return null;
    }
}
