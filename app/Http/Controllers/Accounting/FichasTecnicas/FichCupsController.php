<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\TipoManual;
use App\Services\Accounting\FichasTecnicas\FichCupsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta de tarifarios: CUPS, homólogos y SOAT.
 *
 * Reemplaza los `cups.php`, `iss.php`, `insti.php`, `soat_2022.php` y
 * `soat_2023.php` de cada módulo, más `aprobador/buscar_cups.php` y
 * `datos_cups.php`, junto con
 * los endpoints AJAX `get_cups.php`, `get_grupos.php`, `get_subgrupos.php`
 * y `get_homologos.php`.
 */
class FichCupsController extends BaseFichasController
{
    public function __construct(private readonly FichCupsService $cups)
    {
    }

    // ── CUPS ─────────────────────────────────────────────────────────────

    public function buscarCups(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn (): JsonResponse => $this->paginado($this->cups->buscarCups($request->all())),
            'Error al consultar el catálogo CUPS'
        );
    }

    public function autocompletarCups(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->cups->autocompletarCups(
                (string) $request->input('q', ''),
                (int) $request->input('limit', 20)
            ),
            'Error en el autocompletado de CUPS'
        );
    }

    public function grupos(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->cups->grupos($request->input('resolucion')),
            'Error al obtener los grupos CUPS'
        );
    }

    public function subgrupos(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->cups->subgrupos($request->input('grupo'), $request->input('resolucion')),
            'Error al obtener los subgrupos CUPS'
        );
    }

    // ── Homólogos ────────────────────────────────────────────────────────

    public function buscarHomologos(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn (): JsonResponse => $this->paginado($this->cups->buscarHomologos($request->all())),
            'Error al consultar las homologaciones'
        );
    }

    public function autocompletarHomologos(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->cups->autocompletarHomologos(
                (string) $request->input('q', ''),
                (int) $request->input('limit', 20)
            ),
            'Error en el autocompletado de servicios'
        );
    }

    public function homologosDeCups(string $codeCups): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->cups->homologosDeCups($codeCups),
            'Error al obtener las homologaciones del CUPS'
        );
    }

    /** Tarifario por manual: ISS 2001, SOAT o INSTITUCIONAL. */
    public function tarifario(Request $request, string $manual): JsonResponse
    {
        $mapa = [
            'iss'           => TipoManual::Iss2001,
            'soat'          => TipoManual::Soat,
            'institucional' => TipoManual::Institucional,
        ];

        if (! isset($mapa[$manual])) {
            return response()->json([
                'success' => false,
                'message' => 'Manual no válido. Use: iss, soat o institucional.',
            ], 422);
        }

        return $this->ejecutar(
            fn (): JsonResponse => $this->paginado($this->cups->tarifarioPorManual(
                $mapa[$manual],
                $request->input('buscar'),
                (int) $request->input('per_page', 25)
            )),
            'Error al consultar el tarifario'
        );
    }

    // ── SOAT ─────────────────────────────────────────────────────────────

    public function buscarSoat(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn (): JsonResponse => $this->paginado($this->cups->buscarSoat($request->all())),
            'Error al consultar el tarifario SOAT'
        );
    }

    public function vigenciasSoat(): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->cups->vigenciasSoat(),
            'Error al obtener las vigencias SOAT'
        );
    }

    // ── Trazabilidad ─────────────────────────────────────────────────────

    /** Fichas vigentes que contratan un CUPS determinado. */
    public function fichasPorCups(Request $request, string $cups): JsonResponse
    {
        $vigencia = $request->input('vigencia'); // 'vigente' | 'vencida' | null

        return $this->ejecutar(
            fn () => $this->cups->fichasPorCups(
                $cups,
                array_map('intval', (array) $request->input('id_sucursal', [])),
                array_map('strval', (array) $request->input('sucursal', [])),
                match ($vigencia) {
                    'vigente' => true,
                    'vencida' => false,
                    default   => null,
                }
            ),
            'Error al consultar las fichas por CUPS'
        );
    }
}
