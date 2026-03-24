<?php

namespace App\Http\Controllers;

use App\Models\UsuarioContexto;
use App\Models\Empleado;
use App\Services\EmpleadoService;
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
}
