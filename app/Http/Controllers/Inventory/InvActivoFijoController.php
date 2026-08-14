<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InvTrazActivo;
use App\Services\Inventory\ActivoFijoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Toma de inventario de activos fijos.
 *
 * El maestro de activos es de solo lectura (vista de Fabric `ra.VW_Fixed_DetalleActivos`).
 * Lo que se escribe aquí son las novedades encontradas en sitio, que quedan en
 * `inv_traz_activo` con firma del inventariador.
 *
 * Endpoints (prefijo /api/inventory/activos-fijos):
 *   GET  /columnas              → columnas reales de la vista
 *   GET  /buscar               → busca activos por placa/serie/responsable/artículo
 *   GET  /{placa}              → detalle del activo + historial
 *   GET  /{placa}/historial    → solo el historial
 *   POST /novedad              → registra una novedad de toma
 *   GET  /trazabilidad         → listado paginado de todas las tomas
 *   GET  /trazabilidad/resumen → contadores para el tablero
 */
class InvActivoFijoController extends Controller
{
    public function __construct(
        private readonly ActivoFijoService $activos
    ) {}

    // =========================================================================
    // CONSULTA
    // =========================================================================

    /**
     * GET /api/inventory/activos-fijos/columnas
     */
    public function columnas(): JsonResponse
    {
        $resultado = $this->activos->columnas(auth()->user());

        return $this->responder($resultado);
    }

    /**
     * GET /api/inventory/activos-fijos/buscar?campo=placa&valor=LG2618
     */
    public function buscar(Request $request): JsonResponse
    {
        $request->validate([
            'campo' => 'required|string|in:placa,serie,responsable,articulo',
            'valor' => 'required|string|max:150',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $resultado = $this->activos->buscar(
            auth()->user(),
            $request->string('campo')->toString(),
            $request->string('valor')->toString(),
            (int) $request->input('limit', 50)
        );

        return $this->responder($resultado);
    }

    /**
     * GET /api/inventory/activos-fijos/{placa}
     */
    public function show(string $placa): JsonResponse
    {
        $resultado = $this->activos->detallePorPlaca(auth()->user(), $placa);

        return $this->responder($resultado);
    }

    /**
     * GET /api/inventory/activos-fijos/{placa}/historial
     */
    public function historial(string $placa): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->activos->historial($placa),
        ]);
    }

    // =========================================================================
    // REGISTRO DE NOVEDADES
    // =========================================================================

    /**
     * POST /api/inventory/activos-fijos/novedad
     *
     * Body: placa (requerido) + cualquier combinación de novedad_* + observacion
     */
    public function registrarNovedad(Request $request): JsonResponse
    {
        $estadosFisicos = implode(',', InvTrazActivo::ESTADOS_FISICOS);
        $estados        = implode(',', InvTrazActivo::ESTADOS);

        $validado = $request->validate([
            'placa'                   => 'required|string|max:100',

            'novedad_placa'           => 'nullable|string|max:100',
            'novedad_estado'          => "nullable|string|in:{$estados}",
            'novedad_articulo'        => 'nullable|string|max:255',
            'novedad_marca'           => 'nullable|string|max:150',
            'novedad_modelo'          => 'nullable|string|max:150',
            'novedad_serie'           => 'nullable|string|max:150',
            'novedad_responsable'     => 'nullable|string|max:255',
            'novedad_localizacion'    => 'nullable|string|max:255',
            'novedad_tipo_inventario' => 'nullable|string|max:150',
            'novedad_sucursal'        => 'nullable|string|max:150',
            'novedad_estado_fisico'   => "nullable|string|in:{$estadosFisicos}",

            'observacion'             => 'nullable|string|max:2000',
            'id_empresa'              => 'nullable|integer|exists:ent_empresas,id',
            'id_sucursal'             => 'nullable|integer',
        ], [
            'novedad_estado_fisico.in' => 'El estado físico debe ser: ' . implode(', ', InvTrazActivo::ESTADOS_FISICOS) . '.',
            'novedad_estado.in'        => 'El estado debe ser Activo o Inactivo.',
        ]);

        $resultado = $this->activos->registrarNovedad(auth()->user(), $validado);

        if (!($resultado['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'],
            ], $resultado['code'] ?? 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Novedad registrada correctamente.',
            'data'    => $resultado['data'],
        ], 201);
    }

    // =========================================================================
    // TRAZABILIDAD
    // =========================================================================

    /**
     * GET /api/inventory/activos-fijos/trazabilidad
     */
    public function trazabilidad(Request $request): JsonResponse
    {
        $request->validate([
            'placa'         => 'nullable|string|max:100',
            'estado_fisico' => 'nullable|string|in:' . implode(',', InvTrazActivo::ESTADOS_FISICOS),
            'usuario_id'    => 'nullable|integer|exists:users,id',
            'desde'         => 'nullable|date',
            'hasta'         => 'nullable|date|after_or_equal:desde',
            'per_page'      => 'nullable|integer|min:1|max:100',
        ]);

        $resultado = $this->activos->listar(
            $request->only(['placa', 'estado_fisico', 'usuario_id', 'desde', 'hasta']),
            (int) $request->input('per_page', 25)
        );

        return response()->json([
            'success' => true,
            'data'    => $resultado['data'],
            'meta'    => $resultado['meta'],
        ]);
    }

    /**
     * GET /api/inventory/activos-fijos/trazabilidad/resumen
     */
    public function resumen(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->activos->resumen(),
        ]);
    }

    /**
     * GET /api/inventory/activos-fijos/opciones
     *
     * Catálogos para los selects del formulario de novedades.
     */
    public function opciones(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'estados'         => InvTrazActivo::ESTADOS,
                'estados_fisicos' => InvTrazActivo::ESTADOS_FISICOS,
            ],
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * @param array{success: bool, data?: mixed, total?: int, message?: string, code?: int} $resultado
     */
    private function responder(array $resultado): JsonResponse
    {
        if (!($resultado['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'] ?? 'Error consultando el maestro de activos.',
                'data'    => [],
            ], $resultado['code'] ?? 400);
        }

        $payload = ['success' => true, 'data' => $resultado['data'] ?? []];

        if (isset($resultado['total'])) {
            $payload['total'] = $resultado['total'];
        }

        return response()->json($payload);
    }
}
