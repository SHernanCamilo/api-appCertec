<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Services\Turnos\CalculoHorasService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CalculoHorasController extends Controller
{
    public function __construct(
        private CalculoHorasService $service
    ) {}

    /**
     * GET /api/turnos/calculo/empleado/{idEmpleado}
     * Query: anio, mes
     *
     * Retorna las horas del mes desglosadas en 4 categorías + total.
     */
    public function porEmpleadoMes(Request $request, int $idEmpleado): JsonResponse
    {
        $request->validate([
            'anio' => 'required|integer|min:2020|max:2100',
            'mes'  => 'required|integer|min:1|max:12',
        ]);

        try {
            $resultado = $this->service->calcularMesEmpleado(
                $idEmpleado,
                (int) $request->anio,
                (int) $request->mes
            );

            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al calcular horas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/calculo/empleado/{idEmpleado}/rango
     * Query: desde (Y-m-d), hasta (Y-m-d)
     */
    public function porEmpleadoRango(Request $request, int $idEmpleado): JsonResponse
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        try {
            $resultado = $this->service->calcularRango(
                $idEmpleado,
                $request->desde,
                $request->hasta
            );

            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al calcular horas: ' . $e->getMessage(),
            ], 500);
        }
    }
}
