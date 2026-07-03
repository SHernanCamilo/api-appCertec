<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\CtAsignacion;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use App\Services\TalentoHumano\CuadroTurnos\CuadroTurnoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AsignacionController extends Controller
{
    private CuadroTurnoService $service;

    public function __construct(CuadroTurnoService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/turnos/asignaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $asignacion = CtAsignacion::with(['cuadro.grupo', 'empleado', 'plantilla', 'novedades.novedadTipo'])->findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => array_merge($asignacion->toArray(), [
                    'hora_inicio_efectiva' => $asignacion->getHoraInicio(),
                    'hora_fin_efectiva' => $asignacion->getHoraFin(),
                ])
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Asignación no encontrada.'], 404);
        }
    }

    /**
     * POST /api/turnos/asignaciones
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_cuadro' => 'required|integer|exists:humtal_ct_cuadro,id',
            'id_empleado' => 'required|integer|exists:users,id',
            'fecha' => 'required|date',
            'id_plantilla' => 'nullable|integer|exists:humtal_ct_plantillas,id',
            'es_descanso' => 'boolean',
            'es_festivo' => 'boolean',
            'hora_inicio_override' => 'nullable|date_format:H:i',
            'hora_fin_override' => 'nullable|date_format:H:i',
            'observacion' => 'nullable|string|max:255',
        ]);
        try {
            $asignacion = $this->service->asignarTurno($request->all());
            return response()->json([
                'success' => true,
                'message' => 'Asignación creada exitosamente.',
                'data' => $asignacion->load(['empleado', 'plantilla'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear asignación: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * PUT /api/turnos/asignaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_plantilla' => 'nullable|integer|exists:humtal_ct_plantillas,id',
            'es_descanso' => 'boolean',
            'es_festivo' => 'boolean',
            'hora_inicio_override' => 'nullable|date_format:H:i',
            'hora_fin_override' => 'nullable|date_format:H:i',
            'observacion' => 'nullable|string|max:255',
        ]);
        try {
            $asignacion = CtAsignacion::findOrFail($id);
            $cuadro = CtCuadro::findOrFail($asignacion->id_cuadro);
            if (!$cuadro->esBorrador()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden modificar asignaciones en cuadros en estado borrador.'
                ], 422);
            }
            $idPlantilla = $request->input('id_plantilla', $asignacion->id_plantilla);
            $esDescanso = $request->input('es_descanso', $asignacion->es_descanso);
            if (!$esDescanso && $idPlantilla) {
                $haySolapamiento = $this->service->validarSolapamiento(
                    $asignacion->id_empleado,
                    $asignacion->fecha instanceof \Carbon\Carbon ? $asignacion->fecha->toDateString() : (string) $asignacion->fecha,
                    $idPlantilla,
                    $request->input('hora_inicio_override'),
                    $request->input('hora_fin_override'),
                    $id
                );
                if ($haySolapamiento) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El empleado ya tiene un turno que se solapa con el horario indicado en esa fecha.'
                    ], 422);
                }
            }
            $asignacion->update($request->all());
            return response()->json([
                'success' => true,
                'message' => 'Asignación actualizada.',
                'data' => $asignacion->fresh()->load(['empleado', 'plantilla'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/turnos/asignaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $asignacion = CtAsignacion::findOrFail($id);
            $cuadro = CtCuadro::findOrFail($asignacion->id_cuadro);
            if (!$cuadro->esBorrador()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar asignaciones en cuadros en estado borrador.'
                ], 422);
            }
            $asignacion->delete();
            return response()->json(['success' => true, 'message' => 'Asignación eliminada.']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar asignación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/turnos/empleados/{idEmpleado}/turnos
     */
    public function turnosEmpleado(Request $request, int $idEmpleado): JsonResponse
    {
        $request->validate([
            'anio' => 'required|integer|min:2020|max:2100',
            'mes' => 'required|integer|min:1|max:12'
        ]);
        try {
            $turnos = $this->service->obtenerTurnosEmpleado($idEmpleado, (int) $request->anio, (int) $request->mes);
            return response()->json(['success' => true, 'data' => $turnos]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener turnos del empleado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/turnos/empleados/{idEmpleado}/cuadro-mes
     */
    public function cuadroMesEmpleado(Request $request, int $idEmpleado): JsonResponse
    {
        try {
            $anio = (int) $request->query('anio', date('Y'));
            $mes = (int) $request->query('mes', date('m'));
            $cuadro = $this->service->obtenerCuadroEmpleado($idEmpleado, $anio, $mes);
            return response()->json(['success' => true, 'data' => $cuadro]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener cuadro: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/turnos/cuadros/ensure
     * Asegura que existe un cuadro mensual para una UNIDAD FUNCIONAL.
     * 
     * Acepta dos modos:
     *   A) { id_unidad, anio, mes }     → directo por UF
     *   B) { id_empleado, anio, mes }   → resuelve la UF del empleado vía config_unidades_fun_usuarios
     * 
     * Lógica:
     *   1. Determina id_unidad (directo o resuelto desde el empleado)
     *   2. Busca el grupo asociado a esa unidad (humtal_ct_grupos.id_unidad_funcional)
     *   3. Si no hay grupo, lo crea
     *   4. Busca/crea el cuadro para ese grupo en el mes/año
     *   5. Retorna el id_cuadro para usarlo en asignaciones
     * 
     * Response: { id_cuadro, id_grupo, id_unidad, anio, mes, estado }
     */
    public function ensureCuadroUnidad(Request $request): JsonResponse
    {
        $request->validate([
            'id_unidad'   => 'nullable|integer|exists:config_unidades_funcionales,id',
            'id_empleado' => 'nullable|integer|exists:config_person_tercero,id',
            'anio'        => 'required|integer|min:2020|max:2100',
            'mes'         => 'required|integer|min:1|max:12'
        ]);

        // Al menos uno de los dos debe venir
        if (!$request->filled('id_unidad') && !$request->filled('id_empleado')) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere id_unidad o id_empleado.'
            ], 422);
        }

        try {
            $anio = (int) $request->input('anio');
            $mes = (int) $request->input('mes');

            // ───────────────────────────────────────────────────────────
            // PASO 1: Resolver id_unidad
            // ───────────────────────────────────────────────────────────
            if ($request->filled('id_unidad')) {
                $idUnidad = (int) $request->input('id_unidad');
            } else {
                // Resolver desde el empleado: buscar su UF en config_unidades_fun_usuarios
                $idEmpleado = (int) $request->input('id_empleado');
                $relacion = \DB::table('config_unidades_fun_usuarios')
                    ->where('id_user', $idEmpleado)
                    ->first();

                if (!$relacion) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El empleado no está asignado a ninguna unidad funcional.'
                    ], 422);
                }

                $idUnidad = (int) $relacion->id_unidad_funcional;
            }

            // ───────────────────────────────────────────────────────────
            // PASO 2: Obtener información de la unidad funcional
            // ───────────────────────────────────────────────────────────
            $unidad = \App\Models\Config\ConfigUnidadFuncional::findOrFail($idUnidad);

            // ───────────────────────────────────────────────────────────
            // PASO 3: Buscar o crear el grupo para esta unidad
            // ───────────────────────────────────────────────────────────
            $grupo = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::where('id_unidad_funcional', $idUnidad)
                ->where('estado', true)
                ->first();

            if (!$grupo) {
                $grupo = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::create([
                    'codigo' => $unidad->codigo . '_' . time(),
                    'nombre' => $unidad->nombre,
                    'descripcion' => 'Grupo generado automáticamente para cuadro de turnos',
                    'id_empresa' => $unidad->id_empresa,
                    'id_sede' => $unidad->id_sede,
                    'id_unidad_funcional' => $idUnidad,
                    'estado' => true,
                ]);

                \Log::info('Grupo creado automáticamente', [
                    'id_grupo' => $grupo->id,
                    'id_unidad' => $idUnidad,
                    'nombre' => $grupo->nombre
                ]);
            }

            // ───────────────────────────────────────────────────────────
            // PASO 4: Buscar o crear el cuadro para este grupo/mes/año
            // ───────────────────────────────────────────────────────────
            $cuadro = CtCuadro::where('id_grupo', $grupo->id)
                ->where('anio', $anio)
                ->where('mes', $mes)
                ->first();

            if (!$cuadro) {
                $usuario = auth()->user();
                $cuadro = CtCuadro::create([
                    'id_grupo' => $grupo->id,
                    'anio' => $anio,
                    'mes' => $mes,
                    'estado' => 'borrador',
                    'creado_por' => $usuario->id ?? 0
                ]);

                \Log::info('Cuadro de turnos creado', [
                    'id_cuadro' => $cuadro->id,
                    'id_grupo' => $grupo->id,
                    'id_unidad' => $idUnidad,
                    'anio' => $anio,
                    'mes' => $mes
                ]);
            }

            // ───────────────────────────────────────────────────────────
            // PASO 5: Retornar respuesta con todos los datos
            // ───────────────────────────────────────────────────────────
            return response()->json([
                'success' => true,
                'data' => [
                    'id_cuadro' => $cuadro->id,
                    'id_grupo' => $grupo->id,
                    'id_unidad' => $idUnidad,
                    'anio' => $anio,
                    'mes' => $mes,
                    'estado' => $cuadro->estado
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en ensureCuadroUnidad', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al asegurar cuadro: ' . $e->getMessage()
            ], 422);
        }
    }

 /**
 * DELETE /api/turnos/empleados/{idEmpleado}/cuadro-mes
 * Elimina todos los turnos del empleado en un mes/año
 */
public function eliminarCuadroMesEmpleado(Request $request, int $idEmpleado): JsonResponse
{
    try {
        $anio = (int) $request->query('anio', date('Y'));
        $mes = (int) $request->query('mes', date('m'));

        $resultado = $this->service->eliminarCuadroEmpleado($idEmpleado, $anio, $mes);

        return response()->json([
            'success' => true,
            'message' => 'Cuadro de turnos eliminado exitosamente',
            'data' => $resultado
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al eliminar cuadro: ' . $e->getMessage()
        ], 500);
    }
 }
}