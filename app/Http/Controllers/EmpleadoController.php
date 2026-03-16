<?php

namespace App\Http\Controllers;

use App\Services\EmpleadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmpleadoController extends Controller
{
    public function __construct(private EmpleadoService $empleadoService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $empleados = $this->empleadoService->listar($request->all());
            return response()->json($empleados, 200);
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
}
