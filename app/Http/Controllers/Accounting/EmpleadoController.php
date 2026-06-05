<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\UsuarioContexto;
use App\Models\Empleado;
use App\Services\Accounting\EmpleadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmpleadoController extends Controller
{
    public function __construct(private EmpleadoService $empleadoService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user    = auth('api')->user();
            $filters = $request->all();

            // Si no viene id_empresa en el request, usar el contexto del usuario
            if (empty($filters['id_empresa'])) {
                $contexto = UsuarioContexto::where('user_id', $user->id)->first();

                Log::channel('daily')->info('📋 Listado de empleados', [
                    'user_id'            => $user->id,
                    'email'              => $user->email,
                    'id_empresa_request' => $request->input('id_empresa'),
                    'id_empresa_contexto'=> $contexto?->empresa_id,
                    'filtros'            => $filters,
                    'ip'                 => $request->ip(),
                ]);

                if ($contexto?->empresa_id) {
                    $filters['id_empresa'] = $contexto->empresa_id;
                } else {
                    Log::warning('⚠️ Usuario sin contexto de empresa intentando listar empleados', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                    ]);
                }
            }

            $empleados = $this->empleadoService->listar($filters);

            // simplePaginate no tiene total/lastPage, paginate sí
            $meta = [
                'current_page' => $empleados->currentPage(),
                'per_page'     => $empleados->perPage(),
                'has_more'     => $empleados->hasMorePages(),
            ];
            if (method_exists($empleados, 'total')) {
                $meta['total']     = $empleados->total();
                $meta['last_page'] = $empleados->lastPage();
            }

            return response()->json([
                'data' => $empleados->items(),
                'meta' => $meta,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los empleados',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $empleado = $this->empleadoService->crear($request->all());
            return response()->json([
                'message' => 'Empleado creado exitosamente',
                'data' => $empleado
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el empleado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $empleado = $this->empleadoService->actualizar((int) $id, $request->all());
            return response()->json([
                'message' => 'Empleado actualizado exitosamente',
                'data' => $empleado
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el empleado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->empleadoService->eliminar((int) $id);
            return response()->json([
                'message' => 'Empleado eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el empleado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Busca si el usuario autenticado existe como tercero en config_person_tercero
     * usando su numero_identificacion. No relaciona modelos, solo consulta por documento.
     */
    public function buscarPorDocumentoActual(): JsonResponse
    {
        $user = auth('api')->user();

        if (empty($user->numero_identificacion)) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene número de identificación registrado',
                'data'    => null,
            ], 404);
        }

        $tercero = Empleado::with(['empresa', 'cargoRelacion'])
            ->where('numero_identificacion', $user->numero_identificacion)
            ->first();

        if (!$tercero) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un registro en terceros con el documento ' . $user->numero_identificacion,
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $tercero,
        ]);
    }

    /**
     * Devuelve las unidades funcionales únicas registradas en la tabla terceros
     * (config_person_tercero), tomadas de la columna 'unidad'.
     * Además, intenta asociar la sede basándose en el sufijo del nombre de la unidad.
     */
    public function unidadesDisponibles(Request $request): JsonResponse
    {
        try {
            $query = Empleado::query()
                ->whereNotNull('unidad')
                ->where('unidad', '!=', '');

            // Filtro opcional por empresa
            if ($request->filled('id_empresa')) {
                $query->where('id_empresa', $request->id_empresa);
            }

            $unidades = $query->select('unidad')
                ->distinct()
                ->orderBy('unidad')
                ->pluck('unidad')
                ->values();

            // Mapeo manual de acrónimos a sedes
            $sedeMap = [
                'NVA' => 'Neiva',
                'TJA' => 'Tunja',
                'KTA' => 'Facatativa',
            ];

            // Mapear unidades con su sede
            $resultado = $unidades->map(function ($unidad) use ($sedeMap) {
                $sede = null;
                
                // Extraer el sufijo después del último guión
                if (str_contains($unidad, '-')) {
                    $partes = explode('-', $unidad);
                    $ultimaParte = end($partes);
                    if (strlen(trim($ultimaParte)) >= 2 && strlen(trim($ultimaParte)) <= 4) {
                        $sufijo = strtoupper(trim($ultimaParte));
                        if (isset($sedeMap[$sufijo])) {
                            $sede = $sedeMap[$sufijo];
                        }
                    }
                }

                return [
                    'unidad' => $unidad,
                    'sede'   => $sede,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las unidades',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Devuelve los empleados (terceros) que pertenecen a una unidad funcional.
     */
    public function empleadosPorUnidad(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'unidad' => 'required|string',
            ]);

            $query = Empleado::query()
                ->where('unidad', $request->unidad)
                ->where('estado', true);

            // Filtro opcional por empresa
            if ($request->filled('id_empresa')) {
                $query->where('id_empresa', $request->id_empresa);
            }

            $empleados = $query->select('id', 'nombre', 'email', 'numero_identificacion', 'unidad')
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $empleados,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los empleados de la unidad',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Devuelve los turnos de todos los empleados de una unidad funcional para un mes.
     * Vista tipo grilla: filas = empleados, columnas = días del mes.
     */
    public function turnosUnidadMes(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'unidad' => 'required|string',
                'mes'    => 'required|integer|between:1,12',
                'anio'   => 'required|integer|between:2020,2030',
            ]);

            $unidad = $request->unidad;
            $mes    = (int) $request->mes;
            $anio   = (int) $request->anio;

            // Obtener empleados de la unidad
            $empleados = Empleado::where('unidad', $unidad)
                ->where('estado', true)
                ->select('id', 'nombre', 'numero_identificacion')
                ->orderBy('nombre')
                ->get();

            if ($empleados->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data'    => [],
                    'meta'    => ['mes' => $mes, 'anio' => $anio, 'unidad' => $unidad, 'dias_mes' => cal_days_in_month(CAL_GREGORIAN, $mes, $anio)],
                ]);
            }

            $fechaInicio = \Carbon\Carbon::createFromDate($anio, $mes, 1)->startOfMonth()->toDateString();
            $fechaFin    = \Carbon\Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();
            $diasMes     = \Carbon\Carbon::createFromDate($anio, $mes, 1)->daysInMonth;

            // Obtener todas las asignaciones de estos empleados en el mes
            $idsEmpleados = $empleados->pluck('id')->toArray();

            $asignaciones = \App\Models\Turnos\CtAsignacion::with(['plantilla'])
                ->whereIn('id_empleado', $idsEmpleados)
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->get()
                ->groupBy('id_empleado');

            // Construir la grilla
            $grilla = $empleados->map(function ($empleado) use ($asignaciones, $diasMes) {
                $turnosEmpleado = $asignaciones->get($empleado->id, collect());
                
                // Indexar por día
                $turnosPorDia = [];
                foreach ($turnosEmpleado as $asig) {
                    $dia = (int) \Carbon\Carbon::parse($asig->fecha)->day;
                    $turnosPorDia[$dia] = [
                        'es_descanso' => $asig->es_descanso,
                        'plantilla'   => $asig->plantilla ? [
                            'nombre'    => $asig->plantilla->nombre,
                            'color_hex' => $asig->plantilla->color_hex,
                        ] : null,
                    ];
                }

                return [
                    'id'     => $empleado->id,
                    'nombre' => $empleado->nombre,
                    'cedula' => $empleado->numero_identificacion,
                    'dias'   => $turnosPorDia,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $grilla,
                'meta'    => [
                    'mes'      => $mes,
                    'anio'     => $anio,
                    'unidad'   => $unidad,
                    'dias_mes' => $diasMes,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los turnos de la unidad',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
