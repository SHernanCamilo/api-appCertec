<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\CtFrecuencia;
use App\Services\TalentoHumano\CuadroTurnos\FrecuenciaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FrecuenciaController extends Controller
{
    private FrecuenciaService $service;

    public function __construct(FrecuenciaService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/turnos/frecuencias
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtFrecuencia::with(['empleado', 'plantilla'])->activas();

            if ($request->filled('id_empleado')) {
                $query->porEmpleado((int) $request->id_empleado);
            }

            if ($request->filled('tipo_frecuencia')) {
                $query->porTipo($request->tipo_frecuencia);
            }

            $frecuencias = $query->orderBy('created_at', 'desc')->get();

            return response()->json(['success' => true, 'data' => $frecuencias]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al listar frecuencias: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/turnos/frecuencias/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $frecuencia = CtFrecuencia::with(['empleado', 'plantilla', 'cuadro'])->findOrFail($id);
            return response()->json(['success' => true, 'data' => $frecuencia]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Frecuencia no encontrada.'], 404);
        }
    }

    /**
     * POST /api/turnos/frecuencias
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate($this->validationRules());

        try {
            $data = $request->all();
            $data['creado_por'] = auth()->id();
            $frecuencia = $this->service->crear($data);

            return response()->json([
                'success' => true,
                'message' => 'Frecuencia creada exitosamente.',
                'data'    => $frecuencia->load(['empleado', 'plantilla']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear frecuencia: ' . $e->getMessage()], 422);
        }
    }

    /**
     * PUT /api/turnos/frecuencias/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate($this->validationRules(true));

        try {
            $frecuencia = $this->service->actualizar($id, $request->all());
            return response()->json([
                'success' => true,
                'message' => 'Frecuencia actualizada.',
                'data'    => $frecuencia->load(['empleado', 'plantilla']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar frecuencia: ' . $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /api/turnos/frecuencias/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->eliminar($id);
            return response()->json(['success' => true, 'message' => 'Frecuencia eliminada.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar frecuencia: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/turnos/frecuencias/{id}/generar
     */
    public function generar(int $id): JsonResponse
    {
        try {
            $frecuencia = CtFrecuencia::findOrFail($id);

            if (!$frecuencia->tieneProgramacion()) {
                return response()->json(['success' => false, 'message' => 'Esta frecuencia no tiene programaci+好 configurada.'], 422);
            }

            $resultado = $this->service->generarAsignaciones($frecuencia);

            return response()->json([
                'success' => true,
                'message' => "Generaci+好 completada: {$resultado['total_ok']} exitosas, {$resultado['total_err']} errores.",
                'data'    => [
                    'total'     => $resultado['total'],
                    'total_ok'  => $resultado['total_ok'],
                    'total_err' => $resultado['total_err'],
                    'errores'   => $resultado['errores'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al generar asignaciones: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/turnos/frecuencias/generar-directo
     */
    public function generarDirecto(Request $request): JsonResponse
    {
        $request->validate($this->validationRules());

        try {
            $data = $request->all();
            $data['creado_por'] = auth()->id();

            $frecuencia = $this->service->crear($data);

            \Log::info('Frecuencia creada, iniciando generaci+好', [
                'id' => $frecuencia->id,
                'id_empleado' => $frecuencia->id_empleado,
                'tipo' => $frecuencia->tipo_frecuencia,
                'fecha_inicio' => $frecuencia->fecha_inicio,
                'fecha_fin' => $frecuencia->fecha_fin,
                'dias_semana' => $frecuencia->dias_semana,
            ]);

            if (!$frecuencia->tieneProgramacion()) {
                return response()->json(['success' => false, 'message' => 'El tipo de frecuencia seleccionado no genera programaci+好.'], 422);
            }

            $resultado = $this->service->generarAsignaciones($frecuencia);

            \Log::info('Frecuencia generar-directo resultado', [
                'id_empleado' => $frecuencia->id_empleado,
                'tipo' => $frecuencia->tipo_frecuencia,
                'total_ok' => $resultado['total_ok'],
                'total_err' => $resultado['total_err'],
                'errores' => $resultado['errores'],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Programaci+好 creada: {$resultado['total_ok']} turnos asignados, {$resultado['total_err']} errores.",
                'data'    => [
                    'frecuencia' => $frecuencia->load(['empleado', 'plantilla']),
                    'total'      => $resultado['total'],
                    'total_ok'   => $resultado['total_ok'],
                    'total_err'  => $resultado['total_err'],
                    'errores'    => $resultado['errores'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al generar programaci+好: ' . $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/turnos/frecuencias/previsualizar
     */
    public function previsualizar(Request $request): JsonResponse
    {
        $request->validate([
            'tipo_frecuencia'     => 'required|in:sin_programacion,por_numero_dias,por_dias_semana,dias_del_mes',
            'fecha_inicio'        => 'required|date',
            'fecha_fin'           => 'required|date|after_or_equal:fecha_inicio',
            'cada_n_dias'         => 'nullable|integer|min:1|max:365',
            'dias_semana'         => 'nullable|array',
            'dias_semana.*'       => 'integer|min:0|max:6',
            'dias_mes'            => 'nullable|array',
            'dias_mes.*'          => 'integer|min:1|max:31',
            'incluir_festivos'    => 'boolean',
            'incluir_dominicales' => 'boolean',
        ]);

        try {
            $resultado = $this->service->generarDesdeConfiguracion($request->all());
            return response()->json(['success' => true, 'data' => $resultado]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error en previsualizaci+好: ' . $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/turnos/frecuencias/tipos
     */
    public function tipos(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => CtFrecuencia::tiposDisponibles()]);
    }

    private function validationRules(bool $isUpdate = false): array
    {
        $requiredOrNullable = $isUpdate ? 'nullable' : 'required';

        return [
            'id_empleado'         => "{$requiredOrNullable}|integer|exists:config_person_tercero,id",
            'id_plantilla'        => "nullable|integer|exists:humtal_ct_plantillas,id",
            'tipo_frecuencia'     => "{$requiredOrNullable}|in:sin_programacion,por_numero_dias,por_dias_semana,dias_del_mes",
            'cada_n_dias'         => 'nullable|integer|min:1|max:365',
            'dias_semana'         => 'nullable|array',
            'dias_semana.*'       => 'integer|min:0|max:6',
            'dias_mes'            => 'nullable|array',
            'dias_mes.*'          => 'integer|min:1|max:31',
            'fecha_inicio'        => "{$requiredOrNullable}|date",
            'fecha_fin'           => "{$requiredOrNullable}|date|after_or_equal:fecha_inicio",
            'incluir_festivos'    => 'nullable|boolean',
            'incluir_dominicales' => 'nullable|boolean',
            'es_descanso'         => 'nullable|boolean',
            'hora_inicio_override' => 'nullable',
            'hora_fin_override'   => 'nullable',
            'observacion'         => 'nullable|string|max:255',
        ];
    }
}
