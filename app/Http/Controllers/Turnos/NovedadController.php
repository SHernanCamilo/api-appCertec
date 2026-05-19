<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\CtNovedad;
use App\Services\Turnos\CuadroTurnoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NovedadController extends Controller
{
    public function __construct(
        private CuadroTurnoService $service
    ) {}

    /**
     * GET /api/turnos/novedades
     * Listar novedades con filtros opcionales.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtNovedad::with([
                'empleado',
                'novedadTipo',
                'empleadoReemplaza',
                'solicitadoPor',
                'cuadro.grupo',
            ]);

            if ($request->filled('id_cuadro')) {
                $query->porCuadro((int) $request->id_cuadro);
            }

            if ($request->filled('id_empleado')) {
                $query->porEmpleado((int) $request->id_empleado);
            }

            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }

            $novedades = $query->orderByDesc('created_at')->get();

            return response()->json([
                'success' => true,
                'data'    => $novedades,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener novedades: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/novedades
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_cuadro'             => 'required|integer|exists:humtal_ct_cuadro,id',
            'id_asignacion'         => 'nullable|integer|exists:humtal_ct_asignacion,id',
            'id_empleado'           => 'required|integer|exists:config_person_tercero,id',
            'id_novedad_tipo'       => 'required|integer|exists:humtal_ct_novedad_tipo,id',
            'id_empleado_reemplaza' => 'nullable|integer|exists:config_person_tercero,id',
            'fecha_inicio'          => 'required|date',
            'fecha_fin'             => 'required|date|after_or_equal:fecha_inicio',
            'motivo'                => 'nullable|string',
            'observacion'           => 'nullable|string',
        ]);

        try {
            $data = $request->all();
            $data['solicitado_por'] = auth()->id();
            $data['estado']         = CtNovedad::ESTADO_PENDIENTE;

            $novedad = $this->service->crearNovedad($data);

            return response()->json([
                'success' => true,
                'message' => 'Novedad registrada exitosamente.',
                'data'    => $novedad->load(['empleado', 'novedadTipo', 'empleadoReemplaza']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear novedad: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/turnos/novedades/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $novedad = CtNovedad::with([
                'cuadro.grupo',
                'asignacion.plantilla',
                'empleado',
                'novedadTipo',
                'empleadoReemplaza',
                'solicitadoPor',
                'aprobadoPor',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $novedad,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Novedad no encontrada.',
            ], 404);
        }
    }

    /**
     * PUT /api/turnos/novedades/{id}
     * Solo se pueden editar novedades pendientes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_asignacion'         => 'nullable|integer|exists:humtal_ct_asignacion,id',
            'id_novedad_tipo'       => 'integer|exists:humtal_ct_novedad_tipo,id',
            'id_empleado_reemplaza' => 'nullable|integer|exists:config_person_tercero,id',
            'fecha_inicio'          => 'date',
            'fecha_fin'             => 'date|after_or_equal:fecha_inicio',
            'motivo'                => 'nullable|string',
            'observacion'           => 'nullable|string',
        ]);

        try {
            $novedad = CtNovedad::findOrFail($id);

            if ($novedad->estado !== CtNovedad::ESTADO_PENDIENTE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden editar novedades en estado pendiente.',
                ], 422);
            }

            $novedad->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Novedad actualizada.',
                'data'    => $novedad->fresh()->load(['empleado', 'novedadTipo']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar novedad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/turnos/novedades/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $novedad = CtNovedad::findOrFail($id);

            if ($novedad->estado !== CtNovedad::ESTADO_PENDIENTE) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar novedades en estado pendiente.',
                ], 422);
            }

            $novedad->delete();

            return response()->json([
                'success' => true,
                'message' => 'Novedad eliminada.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar novedad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/novedades/{id}/aprobar
     */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comentario' => 'nullable|string|max:1000',
        ]);

        try {
            $novedad = $this->service->aprobarNovedad($id, auth()->id(), $request->comentario);

            return response()->json([
                'success' => true,
                'message' => 'Novedad aprobada.',
                'data'    => $novedad->load(['aprobadoPor']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al aprobar novedad: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/turnos/novedades/{id}/rechazar
     */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comentario' => 'required|string|max:1000',
        ]);

        try {
            $novedad = $this->service->rechazarNovedad($id, auth()->id(), $request->comentario);

            return response()->json([
                'success' => true,
                'message' => 'Novedad rechazada.',
                'data'    => $novedad->load(['aprobadoPor']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al rechazar novedad: ' . $e->getMessage(),
            ], 422);
        }
    }
}
