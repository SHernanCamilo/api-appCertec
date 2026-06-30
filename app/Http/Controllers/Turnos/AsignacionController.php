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
            'id_empleado' => 'required|integer|exists:config_person_tercero,id',
            'fecha' => 'required|date',
            'id_plantilla' => 'nullable|integer|exists:humtal_ct_plantillas,id',
            'es_descanso' => 'boolean',
            'es_festivo' => 'boolean',
            'hora_inicio_override' => 'nullable|date_format:H:i',
            'hora_fin_override' => 'nullable|date_format:H:i',
            'observacion' => 'nullable|string|max:255',
        ]);

        // SEGURIDAD: Validar que el usuario tiene acceso al cuadro
        $cuadro = CtCuadro::findOrFail($request->id_cuadro);
        if ($cuadro->id_unidad_funcional) {
            $accessControl = new \App\Services\Turnos\AccessControlService(auth()->user());
            if (!$accessControl->tieneAccesoUnidad($cuadro->id_unidad_funcional)) {
                return response()->json(['success' => false, 'message' => 'No tienes acceso a esta unidad.'], 403);
            }
        }

        try {
            $data = $request->all();
            $data['creado_por'] = auth()->id();
            
            \Log::info('📝 ASIGNACIÓN CREADA', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name ?? 'N/A',
                'id_empleado' => $data['id_empleado'] ?? null,
                'fecha' => $data['fecha'] ?? null,
            ]);

            $asignacion = $this->service->asignarTurno($data);
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
            $data = $request->all();
            $data['actualizado_por'] = auth()->id();

            \Log::info('✏️ ASIGNACIÓN ACTUALIZADA', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name ?? 'N/A',
                'id_asignacion' => $id,
                'cambios' => $data,
            ]);

            $asignacion->update($data);
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

            // SEGURIDAD: Validar acceso del usuario a la unidad
            if ($cuadro->id_unidad_funcional) {
                $accessControl = new \App\Services\Turnos\AccessControlService(auth()->user());
                if (!$accessControl->tieneAccesoUnidad($cuadro->id_unidad_funcional)) {
                    return response()->json(['success' => false, 'message' => 'No tienes acceso a esta unidad.'], 403);
                }
            }

            if (!$cuadro->esBorrador()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden eliminar asignaciones en cuadros en estado borrador.'
                ], 422);
            }
            \Log::info('🗑️ ASIGNACIÓN ELIMINADA', [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name ?? 'N/A',
                'id_asignacion' => $id,
                'id_empleado' => $asignacion->id_empleado,
                'fecha' => $asignacion->fecha,
            ]);

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
     * Asegura que existe un cuadro mensual para una UNIDAD FUNCIONAL
     * 
     * Lógica:
     *   1. Recibe id_unidad (config_unidades_funcionales.id)
     *   2. Busca el grupo asociado a esa unidad (humtal_ct_grupos.id_unidad_funcional)
     *   3. Si no hay grupo, lo crea
     *   4. Busca/crea el cuadro para ese grupo en el mes/año
     *   5. Retorna el id_cuadro para usarlo en asignaciones
     * 
     * Body: { id_unidad, anio, mes }
     * Response: { id_cuadro, id_grupo, id_unidad, anio, mes, estado }
     */
    public function ensureCuadroUnidad(Request $request): JsonResponse
    {
        // Si viene id_empleado, crear cuadro por empleado directamente
        if ($request->filled('id_empleado')) {
            return $this->ensureCuadroEmpleado($request);
        }

        $request->validate([
            'id_unidad' => 'required|integer|exists:config_unidades_funcionales,id',
            'anio' => 'required|integer|min:2020|max:2100',
            'mes' => 'required|integer|min:1|max:12'
        ]);

        try {
            $idUnidad = (int) $request->input('id_unidad');
            $anio = (int) $request->input('anio');
            $mes = (int) $request->input('mes');

            // Buscar cuadro existente por unidad funcional + mes + año
            $cuadro = CtCuadro::where('id_unidad_funcional', $idUnidad)
                ->where('anio', $anio)
                ->where('mes', $mes)
                ->first();

            if (!$cuadro) {
                $cuadro = CtCuadro::create([
                    'id_unidad_funcional' => $idUnidad,
                    'anio' => $anio,
                    'mes' => $mes,
                    'estado' => 'borrador',
                    'creado_por' => auth()->id(),
                ]);

                \Log::info('Cuadro de turnos creado (por unidad funcional)', [
                    'id_cuadro' => $cuadro->id,
                    'id_unidad' => $idUnidad,
                    'anio' => $anio,
                    'mes' => $mes
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id_cuadro' => $cuadro->id,
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
     * Asegura que existe un cuadro para un empleado (busca/crea la unidad funcional)
     */
    private function ensureCuadroEmpleado(Request $request): JsonResponse
    {
        $request->validate([
            'id_empleado' => 'required|integer',
            'anio' => 'required|integer|min:2020|max:2100',
            'mes' => 'required|integer|min:1|max:12'
        ]);

        try {
            $idEmpleado = (int) $request->input('id_empleado');
            $anio = (int) $request->input('anio');
            $mes = (int) $request->input('mes');

            // Obtener datos del tercero para saber su unidad y empresa
            $tercero = \DB::table('config_person_tercero')->find($idEmpleado);
            
            if (!$tercero) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empleado no encontrado en terceros'
                ], 404);
            }

            $nombreUnidad = $tercero->unidad;
            $idEmpresa = $tercero->id_empresa;

            if (!$nombreUnidad || !$idEmpresa) {
                return response()->json([
                    'success' => false,
                    'message' => 'El empleado no tiene unidad o empresa asignada'
                ], 422);
            }

            // Buscar o crear la unidad funcional
            $unidad = \App\Models\Turnos\ConfigUnidadFuncional::firstOrCreate(
                [
                    'nombre' => $nombreUnidad,
                    'id_empresa' => $idEmpresa,
                ],
                [
                    'codigo' => strtoupper(str_replace(' ', '-', substr($nombreUnidad, 0, 20))) . '-' . $idEmpresa,
                    'estado' => true,
                ]
            );

            // Buscar o crear cuadro por unidad funcional
            $cuadro = CtCuadro::where('id_unidad_funcional', $unidad->id)
                ->where('anio', $anio)
                ->where('mes', $mes)
                ->first();

            if (!$cuadro) {
                $cuadro = CtCuadro::create([
                    'id_unidad_funcional' => $unidad->id,
                    'anio' => $anio,
                    'mes' => $mes,
                    'estado' => 'borrador',
                    'creado_por' => auth()->id(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id_cuadro' => $cuadro->id,
                    'id_unidad_funcional' => $unidad->id,
                    'anio' => $cuadro->anio,
                    'mes' => $cuadro->mes,
                    'estado' => $cuadro->estado,
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en ensureCuadroEmpleado', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al asegurar cuadro del empleado: ' . $e->getMessage()
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