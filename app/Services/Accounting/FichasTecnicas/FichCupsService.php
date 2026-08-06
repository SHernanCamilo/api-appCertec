<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\TipoManual;
use App\Models\Accounting\FichasTecnicas\FichCups;
use App\Models\Accounting\FichasTecnicas\FichHomologo;
use App\Models\Accounting\FichasTecnicas\FichSoat;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Consultas sobre tarifarios: CUPS, homólogos y SOAT.
 *
 * Refactor respecto al legacy:
 *  - `visor/cups.php`, `generador/cups.php`, etc. hacían
 *    `SELECT ... FROM cups_2641` sin LIMIT y renderizaban las ~9.400 filas en
 *    el HTML para que DataTables paginara en el navegador. Aquí todo va
 *    paginado y filtrado en el servidor.
 *  - `ajax/get_cups.php` traía `SELECT DISTINCT code_cups, desc_cups FROM
 *    homologos` completo (~14.000 filas) en cada carga del formulario.
 *    Ahora es autocompletado con mínimo de caracteres.
 *  - Los catálogos pequeños (grupos y subgrupos CUPS) se cachean: el legacy
 *    los consultaba con `SELECT DISTINCT` en cada request.
 */
final class FichCupsService
{
    private const CACHE_TTL = 86400; // 24 h: son catálogos normativos

    // ─────────────────────────────────────────────────────────────────────
    // CUPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function buscarCups(array $filtros = []): Paginator
    {
        $query = FichCups::query();

        $resolucion = (string) ($filtros['resolucion'] ?? FichCups::RESOLUCION_VIGENTE);
        $query->deResolucion($resolucion);

        if (! empty($filtros['buscar'])) {
            $query->buscar((string) $filtros['buscar']);
        }

        if (! empty($filtros['grupo'])) {
            $query->where('grupo', (string) $filtros['grupo']);
        }

        if (! empty($filtros['subgrupo'])) {
            $query->where('subgrupo', (string) $filtros['subgrupo']);
        }

        $perPage = min(max((int) ($filtros['per_page'] ?? 25), 5), 200);

        return $query
            ->select(['id', 'resolucion', 'subcategoria', 'desc_subcat', 'grupo', 'desc_grup', 'subgrupo', 'desc_subg', 'desc_cap', 'tipo_serv', 'pbs'])
            ->orderBy('subcategoria')
            ->paginate($perPage);
    }

    /** Autocompletado de CUPS (mínimo 2 caracteres). */
    public function autocompletarCups(string $termino, int $limite = 20): Collection
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return collect();
        }

        return FichCups::query()
            ->vigente()
            ->buscar($termino)
            ->select(['subcategoria', 'desc_subcat', 'grupo', 'subgrupo'])
            ->limit(min($limite, 50))
            ->get();
    }

    /**
     * Grupos CUPS de la resolución vigente (catálogo cacheado).
     *
     * Reemplaza `ajax/get_grupos.php`.
     */
    public function grupos(?string $resolucion = null): Collection
    {
        $resolucion = $resolucion ?? FichCups::RESOLUCION_VIGENTE;

        return Cache::remember(
            "fich:cups:grupos:{$resolucion}",
            self::CACHE_TTL,
            static fn (): Collection => FichCups::query()
                ->deResolucion($resolucion)
                ->whereNotNull('grupo')
                ->where('grupo', '!=', '')
                ->select('grupo', 'desc_grup')
                ->distinct()
                ->orderBy('desc_grup')
                ->get()
        );
    }

    /**
     * Subgrupos CUPS (opcionalmente filtrados por grupo).
     *
     * Reemplaza `ajax/get_subgrupos.php`.
     */
    public function subgrupos(?string $grupo = null, ?string $resolucion = null): Collection
    {
        $resolucion = $resolucion ?? FichCups::RESOLUCION_VIGENTE;
        $clave      = "fich:cups:subgrupos:{$resolucion}:".($grupo ?? 'all');

        return Cache::remember(
            $clave,
            self::CACHE_TTL,
            static fn (): Collection => FichCups::query()
                ->deResolucion($resolucion)
                ->whereNotNull('subgrupo')
                ->where('subgrupo', '!=', '')
                ->when($grupo !== null && $grupo !== '', fn ($q) => $q->where('grupo', $grupo))
                ->select('subgrupo', 'desc_subg', 'grupo')
                ->distinct()
                ->orderBy('desc_subg')
                ->get()
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Homólogos
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function buscarHomologos(array $filtros = []): Paginator
    {
        $query = FichHomologo::query()->activos()->with('tipoServicio:id,descripcion');

        if (! empty($filtros['tipo_manual'])) {
            $query->deManual((string) $filtros['tipo_manual']);
        }

        if (! empty($filtros['code_cups'])) {
            $query->deCups((string) $filtros['code_cups']);
        }

        if (! empty($filtros['id_tipo_servicio'])) {
            $query->where('id_tipo_servicio', (int) $filtros['id_tipo_servicio']);
        }

        if (! empty($filtros['buscar'])) {
            $query->buscar((string) $filtros['buscar']);
        }

        $perPage = min(max((int) ($filtros['per_page'] ?? 25), 5), 200);

        return $query->orderBy('code_cups')->paginate($perPage);
    }

    /**
     * Homólogos de un CUPS concreto (cascada del formulario del generador).
     *
     * Reemplaza `ajax/get_homologos.php` y `generador/select_manual.php`.
     */
    public function homologosDeCups(string $codeCups): Collection
    {
        return FichHomologo::query()
            ->activos()
            ->deCups($codeCups)
            ->select(['id', 'code_cups', 'desc_cups', 'tipo_manual', 'code_manual', 'desc_manual', 'uvr_grupo', 'vlr_cirujano', 'vlr_aneste', 'valor', 'pbs'])
            ->orderBy('tipo_manual')
            ->orderBy('code_manual')
            ->get();
    }

    /** Autocompletado de servicios contratables. */
    public function autocompletarHomologos(string $termino, int $limite = 20): Collection
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return collect();
        }

        return FichHomologo::query()
            ->activos()
            ->buscar($termino)
            ->select(['id', 'code_cups', 'desc_cups', 'tipo_manual', 'code_manual', 'desc_manual', 'valor'])
            ->limit(min($limite, 50))
            ->get();
    }

    /** Tarifario completo de un manual (ISS / SOAT / INSTITUCIONAL). */
    public function tarifarioPorManual(TipoManual|string $manual, ?string $buscar = null, int $perPage = 25): Paginator
    {
        return FichHomologo::query()
            ->activos()
            ->deManual($manual)
            ->when($buscar !== null && $buscar !== '', fn ($q) => $q->buscar($buscar))
            ->select(['id', 'code_cups', 'desc_cups', 'code_manual', 'desc_manual', 'uvr_grupo', 'vlr_cirujano', 'vlr_aneste', 'valor', 'pbs'])
            ->orderBy('code_manual')
            ->paginate(min(max($perPage, 5), 200));
    }

    // ─────────────────────────────────────────────────────────────────────
    // SOAT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function buscarSoat(array $filtros = []): Paginator
    {
        $query = FichSoat::query()->deVigencia((int) ($filtros['vigencia'] ?? 2023));

        if (! empty($filtros['buscar'])) {
            $query->buscar((string) $filtros['buscar']);
        }

        if (! empty($filtros['grupo'])) {
            $query->where('grupo', (int) $filtros['grupo']);
        }

        $perPage = min(max((int) ($filtros['per_page'] ?? 25), 5), 200);

        return $query->orderBy('cod')->paginate($perPage);
    }

    /** Vigencias SOAT disponibles. */
    public function vigenciasSoat(): Collection
    {
        return Cache::remember(
            'fich:soat:vigencias',
            self::CACHE_TTL,
            static fn (): Collection => FichSoat::query()
                ->select('vigencia')
                ->distinct()
                ->orderByDesc('vigencia')
                ->pluck('vigencia')
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Trazabilidad de un CUPS en fichas vigentes
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fichas vigentes que contratan un CUPS determinado.
     *
     * Reemplaza `aprobador/datos_cups.php`, que además de construir la consulta
     * con `call_user_func_array` sobre `bind_param`, pintaba el HTML de la tabla
     * directamente desde PHP.
     *
     * @param  list<int>     $idsSucursal
     * @param  list<string>  $sucursalesLegacy
     * @return Collection<int, object>
     */
    public function fichasPorCups(
        string $cups,
        array $idsSucursal = [],
        array $sucursalesLegacy = [],
        ?bool $soloVigentes = null,
    ): Collection {
        $query = DB::table('v_fich_detalles_completo as d')
            ->join('v_fich_fichas_listado as f', 'f.id', '=', 'd.id_ficha')
            ->where('d.cups', $cups)
            ->whereIn('f.estado_codigo', \App\Enums\FichasTecnicas\EstadoFicha::codigos(
                \App\Enums\FichasTecnicas\EstadoFicha::finalizadas()
            ));

        if ($idsSucursal !== []) {
            $query->whereIn('f.id_sucursal', $idsSucursal);
        }

        if ($sucursalesLegacy !== []) {
            $query->whereIn('f.sucursal_legacy', $sucursalesLegacy);
        }

        if ($soloVigentes === true) {
            $query->whereRaw('f.fecha_fin >= CURDATE()');
        } elseif ($soloVigentes === false) {
            $query->whereRaw('f.fecha_fin < CURDATE()');
        }

        return $query
            ->select([
                'f.id',
                'f.consecutivo',
                'f.sucursal_nombre',
                'f.sucursal_legacy',
                'f.empresa_nombre',
                'f.agremiacion_nombre',
                'f.especialidad_descripcion',
                'f.fecha_ini',
                'f.fecha_fin',
                'f.dias_restantes',
                'f.vigencia_estado',
                'f.estado_codigo',
                'd.cups',
                'd.cups_descripcion',
                'd.tipo_liquidacion',
                'd.tipo_servicio',
                'd.forma_pago',
                'd.variacion',
                'd.valor',
            ])
            ->orderBy('f.sucursal_legacy')
            ->orderByDesc('f.fecha_fin')
            ->get();
    }

    /** Invalida los catálogos cacheados (usar tras importar tarifarios). */
    public function limpiarCache(): void
    {
        foreach (FichCups::RESOLUCIONES as $resolucion) {
            Cache::forget("fich:cups:grupos:{$resolucion}");
            Cache::forget("fich:cups:subgrupos:{$resolucion}:all");
        }

        Cache::forget('fich:soat:vigencias');
    }
}
