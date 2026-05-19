<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\CtAsignacion;
use App\Models\Turnos\CtCuadro;
use App\Services\Turnos\CuadroTurnoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AsignacionController extends Controller
{
    public function __construct(
        private CuadroTurnoService $service
    ) {}

    /**
     * GET /api/turnos/asignaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $asignacion = CtAsignacion::with([
                'cuadro.grupo',
                'empleado',
                'plantilla',
                'novedades.novedadTipo',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => array_merge($asignacion->toArray(), [
                    'hora_inicio_efectiva' => $asignacion->getHoraInicio(),
                    'hora_fin_efectiva'    => $asignacion->getHoraFin(),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Asignación no encontrada.',
            ], 404);
        }
    }

    /**
     * POST /api/turnos/asignaciones
     * Crear una asignación individual con validación de solapamiento.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_cuadro'            => 'required|integer|exists:humtal_ct_cuadro,id',
            'id_empleado'          => 'required|integer|exists:config_person_tercero,id',
            'fecha'                => 'required|date',
            'id_plantilla'         => 'nullable|integer|exists:humtal_ct_plantillas,id',
            'es_descanso'          => 'boolean',
            'es_festivo'           => 'boolean',
            'hora_inicio_override' => 'nullable|date_format:H:i',
            'hora_fin_override'    => 'nullable|date_format:H:i',
            'observacion'          => 'nullable|string|max:255',
        ]);

        try {
            $asignacion = $this->service->asignarTurno($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Asignación creada exitosamente.',
                'data'    => $asignacion->load(['empleado', 'plantilla']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear asignación: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PUT /api/turnos/asignaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_plantilla'         => 'nullable|integer|exists:humtal_ct_plantillas,id',
            'es_descanso'          => 'boolean',
            'es_festivo'           => 'boolean',
            'hora_inicio_override' => 'nullable|date_format:H:i',
            'hora_fin_override'    => 'nullable|date_format:H:i',
            'observacion'          => 'nullable|string|max:255',
        ]);

        try {
            $asignacion = CtAsignacion::findOrFail($id);

            // Verificar que el cuadro esté en borrador
            $cuadro = CtCuadro::findOrFail($asignacion->id_cuadro);
            if (!$cuadro->esBorrador()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden modificar asignaciones en cuadros en estado borrador.',
                ], 422);
            }

            // Validar solapamiento si cambia la plantilla
            $idPlantilla = $request->input('id_plantilla', $asignacion->id_plantilla);
            $esDescanso  = $request->input('es_descanso', $asignacion->es_descanso);

            if (!$esDescanso && $idPlantilla) {
                $haySolapamiento = $this->service->validarSolapamiento(
                    $asignacion->id_empleado,
                    $asignacion->fecha instanceof \Carbon\Carbon
                        ? $asignacion->fecha->toDateString()
                        : (string) $asignacion->fecha,
                    $idPlantilla,
                    $request->input('hora_inicio_override'),
                    $request->input('hora_fin_override'),
                    $id
                );

                if ($haySolapamiento) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El empleado ya tiene un turno que se solapa con el horario indicado en esa fecha.',
                    ], 422);
                }
            }

            $asignacion->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Asignación actualizada.',
                'data'    => $asignacion->fresh()->load(['empleado', 'plantilla']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar asignación: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/turnos/asignaciones/{id}
     * Solo si el cuadro está en borrador.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $asignacion = CtAsignacion::findOrFail($id);

            $cuadro = CtCuadro::findOrFail($asignacion->id_cuadro);
            if (!$cuadro->esBorrador()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar asignaciones en cuadros en estado borrador.',
                ], 422);
            }

            $asignacion->delete();

            return response()->json([
                'success' => true,
                'message' => 'Asignación eliminada.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar asignación: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/empleados/{idEmpleado}/turnos
     * Obtener todos los turnos de un empleado en un mes/año.
     */
    public function turnosEmpleado(Request $request, int $idEmpleado): JsonResponse
    {
        $request->validate([
            'anio' => 'required|integer|min:2020|max:2100',
            'mes'  => 'required|integer|min:1|max:12',
        ]);

        try {
            $turnos = $this->service->obtenerTurnosEmpleado(
                $idEmpleado,
                (int) $request->anio,
                (int) $request->mes
            );

            return response()->json([
                'success' => true,
                'data'    => $turnos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener turnos del empleado: ' . $e->getMessage(),
            ], 500);
        }
    }
}
