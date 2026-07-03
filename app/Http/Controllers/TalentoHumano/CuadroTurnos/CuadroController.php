<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use App\Services\TalentoHumano\CuadroTurnos\CuadroTurnoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CuadroController extends Controller
{
    public function __construct(
        private CuadroTurnoService $service
    ) {}

    /**
     * GET /api/turnos/cuadros
     * Listar cuadros con filtros opcionales.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtCuadro::with(['grupo', 'creadoPor']);

            if ($request->filled('id_grupo')) {
                $query->porGrupo((int) $request->id_grupo);
            }

            if ($request->filled('anio')) {
                $query->where('anio', (int) $request->anio);
            }

            if ($request->filled('mes')) {
                $query->where('mes', (int) $request->mes);
            }

            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }

            $cuadros = $query->orderByDesc('anio')->orderByDesc('mes')->get();

            return response()->json([
                'success' => true,
                'data'    => $cuadros,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener cuadros: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/cuadros
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_grupo'     => 'required|integer|exists:humtal_ct_grupos,id',
            'anio'         => 'required|integer|min:2020|max:2100',
            'mes'          => 'required|integer|min:1|max:12',
            'observaciones' => 'nullable|string',
        ]);

        try {
            $cuadro = $this->service->crearCuadro(
                $request->id_grupo,
                $request->anio,
                $request->mes,
                auth()->id()
            );

            if ($request->filled('observaciones')) {
                $cuadro->update(['observaciones' => $request->observaciones]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cuadro creado exitosamente.',
                'data'    => $cuadro->load('grupo'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear cuadro: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/turnos/cuadros/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $cuadro = CtCuadro::with([
                'grupo.empresa',
                'grupo.sede',
                'creadoPor',
                'publicadoPor',
                'cerradoPor',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => array_merge($cuadro->toArray(), [
                    'nombre_mes' => $cuadro->getNombreMes(),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cuadro no encontrado.',
            ], 404);
        }
    }

    /**
     * DELETE /api/turnos/cuadros/{id}
     * Solo se puede eliminar si está en borrador.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $cuadro = CtCuadro::findOrFail($id);

            if (!$cuadro->esBorrador()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar cuadros en estado borrador.',
                ], 422);
            }

            $cuadro->asignaciones()->delete();
            $cuadro->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cuadro eliminado.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar cuadro: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/cuadros/{id}/publicar
     */
    public function publicar(int $id): JsonResponse
    {
        try {
            $cuadro = $this->service->publicarCuadro($id, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Cuadro publicado exitosamente.',
                'data'    => $cuadro,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al publicar cuadro: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/turnos/cuadros/{id}/cerrar
     */
    public function cerrar(int $id): JsonResponse
    {
        try {
            $cuadro = $this->service->cerrarCuadro($id, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Cuadro cerrado exitosamente.',
                'data'    => $cuadro,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar cuadro: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/turnos/cuadros/{id}/grilla
     * Obtener la grilla completa: empleados × días del mes.
     */
    public function grilla(int $id): JsonResponse
    {
        try {
            $resultado = $this->service->obtenerCuadroGrilla($id);

            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener grilla: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/cuadros/{id}/asignaciones
     * Asignación masiva de turnos.
     */
    public function asignarMasivo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'asignaciones'                            => 'required|array|min:1',
            'asignaciones.*.id_empleado'              => 'required|integer|exists:config_person_tercero,id',
            'asignaciones.*.fecha'                    => 'required|date',
            'asignaciones.*.id_plantilla'             => 'nullable|integer|exists:humtal_ct_plantillas,id',
            'asignaciones.*.es_descanso'              => 'boolean',
            'asignaciones.*.es_festivo'               => 'boolean',
            'asignaciones.*.hora_inicio_override'     => 'nullable|date_format:H:i',
            'asignaciones.*.hora_fin_override'        => 'nullable|date_format:H:i',
            'asignaciones.*.hora_inicio_override_2'   => 'nullable|date_format:H:i',
            'asignaciones.*.hora_fin_override_2'      => 'nullable|date_format:H:i',
            'asignaciones.*.observacion'              => 'nullable|string|max:255',
        ]);

        try {
            $resultado = $this->service->asignarTurnoMasivo($id, $request->asignaciones);

            $statusCode = $resultado['total_err'] > 0 ? 207 : 200;

            return response()->json([
                'success' => $resultado['total_err'] === 0,
                'message' => "Procesadas {$resultado['total_ok']} de {$resultado['total']} asignaciones.",
                'data'    => $resultado,
            ], $statusCode);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en asignación masiva: ' . $e->getMessage(),
            ], 500);
        }
    }
}
