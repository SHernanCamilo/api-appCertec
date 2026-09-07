<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\TipoManual;
use App\Services\Accounting\FichasTecnicas\FichCupsService;
use App\Services\Accounting\FichasTecnicas\FichFabricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta de tarifarios: CUPS, homólogos y SOAT.
 *
 * CUPS, homólogos, grupos y subgrupos se leen desde Microsoft Fabric
 * (df.VW_Contract_CUPS y df.VW_Contract_CUPS_Homologos), no de tablas locales,
 * igual que profesionales. Las tablas fich_cups / fich_homologos quedaron vacías.
 *
 * Reemplaza los endpoints AJAX `get_cups.php`, `get_grupos.php`,
 * `get_subgrupos.php` y `get_homologos.php` del legacy.
 */
class FichCupsController extends BaseFichasController
{
    public function __construct(
        private readonly FichCupsService $cups,
        private readonly FichFabricService $fabric,
    ) {
    }

    // ── CUPS (desde Fabric) ───────────────────────────────────────────────

    public function buscarCups(Request $request): JsonResponse
    {
        $q     = trim((string) $request->input('q', $request->input('buscar', '')));
        $limit = (int) $request->input('limit', $request->input('per_page', 50));
        $user  = auth('api')->user();

        try {
            $resultado = $this->fabric->buscarCups($user, $q, $limit);
            return response()->json($resultado, ($resultado['success'] ?? false) ? 200 : 502);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FichCups] Error buscando CUPS Fabric', ['error' => $e->getMessage(), 'q' => $q]);
            return response()->json(['success' => false, 'data' => [], 'total' => 0], 502);
        }
    }

    public function autocompletarCups(Request $request): JsonResponse
    {
        // Alias de buscarCups — el frontend usa este endpoint para el autocomplete
        return $this->buscarCups($request);
    }

    public function grupos(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        try {
            $resultado = $this->fabric->gruposCups($user);
            return response()->json($resultado, ($resultado['success'] ?? false) ? 200 : 502);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FichCups] Error grupos Fabric', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'data' => [], 'total' => 0], 502);
        }
    }

    public function subgrupos(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        try {
            $resultado = $this->fabric->subgruposCups($user);
            return response()->json($resultado, ($resultado['success'] ?? false) ? 200 : 502);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FichCups] Error subgrupos Fabric', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'data' => [], 'total' => 0], 502);
        }
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
        $user = auth('api')->user();

        try {
            // Fabric: filtrar homólogos por el CUPS seleccionado (columna CUPS)
            $resultado = $this->fabric->homologosDeCups($user, $codeCups);
            return response()->json($resultado, ($resultado['success'] ?? false) ? 200 : 502);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FichCups] Error homologos Fabric', ['error' => $e->getMessage(), 'cups' => $codeCups]);
            return response()->json(['success' => false, 'data' => [], 'total' => 0], 502);
        }
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
