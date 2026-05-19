<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Services\Fabric\HcReportViewerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controlador para la vista [DT].[VW_HC_ReportViewer]
 * de Microsoft Fabric / LH_MEDILASER_ANALYTICS.
 *
 * Endpoints:
 *   GET /api/fabric/hc-report-viewer          → Listar con filtros y paginación
 *   GET /api/fabric/hc-report-viewer/columnas → Columnas disponibles en la vista
 */
class HcReportViewerController extends Controller
{
    public function __construct(
        private HcReportViewerService $service
    ) {}

    /**
     * Consultar la vista con filtros opcionales.
     *
     * GET /api/fabric/hc-report-viewer
     *
     * Query params:
     *   - documento_paciente  : string (búsqueda exacta)
     *   - nombre_paciente     : string (búsqueda parcial LIKE)
     *   - nombre_especialista : string (búsqueda parcial LIKE)
     *   - fecha_desde         : date (YYYY-MM-DD)
     *   - fecha_hasta         : date (YYYY-MM-DD)
     *   - per_page            : int (default 50, max 500)
     *   - page                : int (default 1)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'documento_paciente'  => 'nullable|string|max:20',
            'nombre_paciente'     => 'nullable|string|max:150|min:3',
            'nombre_especialista' => 'nullable|string|max:150|min:3',
            'fecha_desde'         => 'nullable|date_format:Y-m-d',
            'fecha_hasta'         => 'nullable|date_format:Y-m-d|after_or_equal:fecha_desde',
            'per_page'            => 'nullable|integer|min:1|max:500',
            'page'                => 'nullable|integer|min:1',
        ]);

        try {
            $resultado = $this->service->consultar($request->only([
                'documento_paciente',
                'nombre_paciente',
                'nombre_especialista',
                'fecha_desde',
                'fecha_hasta',
                'per_page',
                'page',
            ]));

            return response()->json([
                'success'  => true,
                'data'     => $resultado['data'],
                'meta'     => [
                    'total'    => $resultado['total'],
                    'page'     => $resultado['page'],
                    'per_page' => $resultado['per_page'],
                    'pages'    => $resultado['pages'],
                ],
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error consultando Fabric: ' . $e->getMessage(),
            ], 503);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retorna las columnas disponibles en la vista.
     *
     * GET /api/fabric/hc-report-viewer/columnas
     */
    public function columnas(): JsonResponse
    {
        try {
            $columnas = $this->service->columnas();

            return response()->json([
                'success' => true,
                'data'    => $columnas,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo columnas: ' . $e->getMessage(),
            ], 503);
        }
    }
}
