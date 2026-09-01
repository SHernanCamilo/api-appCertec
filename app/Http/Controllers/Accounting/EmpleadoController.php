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

    public function opciones(Request $request): JsonResponse
    {
        try {
            $query = \App\Models\Empleado::select('id', 'nombre', 'email', 'numero_identificacion')
                ->orderBy('nombre');

            if ($request->filled('empresa_id')) {
                $query->where('id_empresa', $request->integer('empresa_id'));
            }

            if ($request->boolean('activos')) {
                $query->where('estado', true);
            }

            if ($request->filled('search') && strlen($request->search) >= 2) {
                $term = $request->search;
                $query->where(function ($q) use ($term) {
                    $q->where('nombre', 'like', '%' . $term . '%')
                      ->orWhere('numero_identificacion', 'like', $term . '%');
                });
            }

            $limit  = (int) $request->input('limit', 100);
            $limit  = min(max($limit, 10), 500);
            $page   = (int) $request->input('page', 1);
            $page   = max($page, 1);
            $offset = ($page - 1) * $limit;

            $data = $query->limit($limit)->offset($offset)->get();

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function cargos(): JsonResponse
    {
        $cargos = \App\Models\Cargo::query()
            ->where('estado', true)
            ->orderBy('nombre_cargo')
            ->get(['id_cargo', 'nombre_cargo']);

        return response()->json(['success' => true, 'data' => $cargos]);
    }

    public function porDocumento(Request $request): JsonResponse
    {
        $documento = trim((string) $request->input('documento', $request->input('numero_identificacion', '')));
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($documento === '' && $email === '') {
            return response()->json([
                'message' => 'Indique documento o correo',
            ], 422);
        }

        $relaciones = ['empresa', 'cargoRelacion', 'usuarioCrea:id,name,email', 'usuarioActualiza:id,name,email', 'usuario:id,name,email,numero_identificacion,tipo_identificacion,telefono,direccion'];

        $personaQuery = Empleado::with($relaciones);

        if ($documento !== '') {
            $personaQuery->where('numero_identificacion', $documento);
            if ($request->filled('id_empresa')) {
                $personaQuery->where('id_empresa', $request->integer('id_empresa'));
            }
        } else {
            $personaQuery->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
        }

        $persona = $personaQuery->first();

        if (!$persona && $documento !== '' && $request->filled('id_empresa')) {
            $persona = Empleado::with($relaciones)
                ->where('numero_identificacion', $documento)
                ->first();
        }

        if (!$persona && $email !== '') {
            $persona = Empleado::with($relaciones)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->first();
        }

        $usuario = null;
        if ($persona?->id_user) {
            $usuario = \App\Models\User::query()
                ->select('id', 'name', 'email', 'numero_identificacion', 'tipo_identificacion', 'telefono', 'direccion', 'estado')
                ->find($persona->id_user);
        }

        if (!$usuario && $documento !== '') {
            $usuario = \App\Models\User::query()
                ->select('id', 'name', 'email', 'numero_identificacion', 'tipo_identificacion', 'telefono', 'direccion', 'estado')
                ->where('numero_identificacion', $documento)
                ->first();
        }

        if (!$usuario && $email !== '') {
            $usuario = \App\Models\User::query()
                ->select('id', 'name', 'email', 'numero_identificacion', 'tipo_identificacion', 'telefono', 'direccion', 'estado')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->first();
        }

        if (!$usuario && $persona?->email) {
            $usuario = \App\Models\User::query()
                ->select('id', 'name', 'email', 'numero_identificacion', 'tipo_identificacion', 'telefono', 'direccion', 'estado')
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($persona->email))])
                ->first();
        }

        if (!$persona && !$usuario) {
            return response()->json([
                'data' => ['persona' => null, 'usuario' => null],
            ], 200);
        }

        return response()->json([
            'data' => [
                'persona' => $persona,
                'usuario' => $usuario,
            ],
        ]);
    }

    public function usuariosLookup(Request $request): JsonResponse
    {

        $auth = auth('api')->user();

        $esAdmin = method_exists($auth, 'rolesCustom') && $auth->rolesCustom()->where('es_admin', true)->exists();

        $empresaIds = method_exists($auth, 'empresas')
            ? $auth->empresas()->pluck('ent_empresas.id')->map(fn ($id) => (int) $id)
            : collect();

        // 4. El usuario ve todo si es admin o si no tiene ninguna empresa asociada.
        $verTodo = $esAdmin || $empresaIds->isEmpty();

        $query = \App\Models\User::query()
            ->select('id', 'name', 'email', 'numero_identificacion', 'tipo_identificacion', 'telefono', 'direccion', 'estado', 'cargo')
            ->orderBy('name');

        if (!$verTodo) {
            $query->whereHas('empresas', function ($q) use ($empresaIds) {
                $q->whereIn('ent_empresas.id', $empresaIds);
            });
        }

        if ($request->filled('id_empresa')) {
            $empresaId = $request->integer('id_empresa');
            if (!$verTodo && !$empresaIds->contains($empresaId)) {
                return response()->json(['message' => 'Sin permiso sobre esa empresa'], 403);
            }
            $query->whereHas('empresas', function ($q) use ($empresaId) {
                $q->where('ent_empresas.id', $empresaId);
            });
        }

        $buscar = trim((string) $request->input('buscar', ''));
        if (strlen($buscar) >= 2) {
            $query->where(function ($q) use ($buscar) {
                $q->where('name', 'like', '%'.$buscar.'%')
                    ->orWhere('email', 'like', '%'.$buscar.'%')
                    ->orWhere('numero_identificacion', 'like', $buscar.'%');
            });
        }

        $perPage = min(max((int) $request->input('per_page', 25), 5), 100);
        $usuarios = $query->paginate($perPage);

        return response()->json([
            'data'  => $usuarios->items(),
            'meta'  => [
                'current_page' => $usuarios->currentPage(),
                'per_page'     => $usuarios->perPage(),
                'total'        => $usuarios->total(),
                'last_page'    => $usuarios->lastPage(),
            ],
            'scope' => $verTodo ? 'todos' : 'empresas',
        ]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->empleadoService->obtener((int) $id),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró el registro',
            ], 404);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user    = auth('api')->user();
            $filters = $request->except(['todas_empresas']);
            $todasEmpresas = filter_var($request->input('todas_empresas'), FILTER_VALIDATE_BOOLEAN);
            $contexto = UsuarioContexto::where('user_id', $user->id)->first();

            // Si no viene id_empresa, el listado de anticipos usa el contexto.
            // El CRUD de personas envía todas_empresas=1 para ver toda la tabla.
            if (empty($filters['id_empresa']) && !$todasEmpresas) {
                if ($contexto?->empresa_id) {
                    $filters['id_empresa'] = $contexto->empresa_id;
                } else {
                    Log::warning('⚠️ Usuario sin contexto de empresa intentando listar empleados', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                    ]);
                }
            }

            Log::channel('daily')->info('📋 Listado de empleados', [
                'user_id'             => $user->id,
                'email'               => $user->email,
                'id_empresa_request'  => $request->input('id_empresa'),
                'id_empresa_contexto' => $contexto?->empresa_id,
                'todas_empresas'      => $todasEmpresas,
                'filtros'             => $filters,
                'ip'                  => $request->ip(),
            ]);

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

        $tercero = Empleado::with(['empresa', 'cargoRelacion', 'usuarioCrea:id,name,email', 'usuarioActualiza:id,name,email'])
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

            $asignaciones = \App\Models\TalentoHumano\CuadroTurnos\CtAsignacion::with(['plantilla'])
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
