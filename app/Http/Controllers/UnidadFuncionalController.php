<?php

namespace App\Http\Controllers;

use App\Services\Config\UnidadFuncionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnidadFuncionalController extends Controller
{
    public function __construct(
        private readonly UnidadFuncionalService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $unidades = $this->service->listar($request->all(), $user);

            return response()->json([
                'success' => true,
                'data' => $unidades->map(fn ($u) => $this->service->formatearRespuesta($u))->values(),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Error al listar unidades funcionales', $e);
        }
    }

    public function buscar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'codigo' => 'required|string|max:20',
            'empresa_id' => 'required|integer|exists:ent_empresas,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth('api')->user();
            $codigo = strtoupper(trim($request->codigo));
            $unidad = $this->service->buscarPorCodigo($codigo, (int) $request->empresa_id, $user);

            return response()->json([
                'success' => true,
                'data' => $unidad ? $this->service->formatearRespuesta($unidad) : null,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Error al buscar la unidad funcional', $e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->validator($request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth('api')->user();
            $unidad = $this->service->crear($validator->validated(), $user);

            return response()->json([
                'success' => true,
                'message' => 'Unidad funcional creada exitosamente',
                'data' => $this->service->formatearRespuesta($unidad),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Error al crear la unidad funcional', $e);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $unidad = $this->service->obtener((int) $id, $user);

            return response()->json([
                'success' => true,
                'data' => $this->service->formatearRespuesta($unidad),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unidad funcional no encontrada',
            ], 404);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validator = $this->validator($request, (int) $id);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = auth('api')->user();
            $unidad = $this->service->actualizar((int) $id, $validator->validated(), $user);

            return response()->json([
                'success' => true,
                'message' => 'Unidad funcional actualizada exitosamente',
                'data' => $this->service->formatearRespuesta($unidad),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Error al actualizar la unidad funcional', $e);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $this->service->eliminar((int) $id, $user);

            return response()->json([
                'success' => true,
                'message' => 'Unidad funcional eliminada exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la unidad funcional',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 404);
        }
    }

    private function validator(Request $request, ?int $id = null): \Illuminate\Contracts\Validation\Validator
    {
        $uniqueCodigo = Rule::unique('config_unidades_funcionales', 'codigo')
            ->where(fn ($query) => $query->where('id_empresa', $request->input('id_empresa')));

        if ($id) {
            $uniqueCodigo->ignore($id);
        }

        return Validator::make($request->all(), [
            'codigo' => ['required', 'string', 'max:20', $uniqueCodigo],
            'nombre' => 'required|string|min:2|max:150',
            'id_empresa' => 'required|integer|exists:ent_empresas,id',
            'id_sucursal' => 'required|integer|exists:config_ubi_sucursales,id',
            'id_sede' => 'nullable|integer|exists:config_ubi_sede,id',
            'estado' => 'nullable|boolean',
            'usuarios_autorizados' => 'nullable|array',
            'usuarios_autorizados.*' => 'integer|exists:config_person_tercero,id',
            'jefes_encargados' => 'nullable|array',
            'jefes_encargados.*' => 'integer|exists:config_person_tercero,id',
        ], [
            'codigo.unique' => 'Ya existe una unidad funcional con este código en la empresa',
            'codigo.required' => 'El código es obligatorio',
            'nombre.required' => 'El nombre es obligatorio',
            'id_empresa.required' => 'Debe seleccionar una empresa',
            'id_sucursal.required' => 'Debe seleccionar una sucursal',
        ]);
    }

    private function errorResponse(string $message, \Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
