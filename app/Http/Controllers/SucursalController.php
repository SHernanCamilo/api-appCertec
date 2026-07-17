<?php

namespace App\Http\Controllers;

use App\Models\Sucursal;
use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SucursalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Sucursal::with('empresa');
            
            // Filtrar por empresa si se proporciona el parámetro
            if ($request->has('id_Empresa')) {
                $query->where('id_Empresa', $request->id_Empresa);
            }
            
            $sucursales = $query->orderBy('created_at', 'desc')->get();
            
            return response()->json($sucursales, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las sucursales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:50',
            'prefijo' => 'nullable|string|max:10',
            'id_Empresa' => 'required|integer|exists:ent_empresas,id'
        ], [
            'nombre.required' => 'El nombre de la sucursal es obligatorio',
            'nombre.max' => 'El nombre no puede exceder 50 caracteres',
            'prefijo.max' => 'El prefijo no puede exceder 10 caracteres',
            'id_Empresa.required' => 'Debe seleccionar una empresa',
            'id_Empresa.exists' => 'La empresa seleccionada no existe'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();
            $data['prefijo'] = $this->normalizarPrefijo($data['prefijo'] ?? null);
            $sucursal = Sucursal::create($data);
            $sucursal->load('empresa');
            
            return response()->json([
                'message' => 'Sucursal creada exitosamente',
                'data' => $sucursal
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la sucursal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $sucursal = Sucursal::with('empresa')->findOrFail($id);
            
            return response()->json($sucursal, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sucursal no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:50',
            'prefijo' => 'sometimes|nullable|string|max:10',
            'id_Empresa' => 'sometimes|required|integer|exists:ent_empresas,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $sucursal = Sucursal::findOrFail($id);
            $data = $validator->validated();
            if (array_key_exists('prefijo', $data)) {
                $data['prefijo'] = $this->normalizarPrefijo($data['prefijo']);
            }
            $sucursal->update($data);
            $sucursal->load('empresa');
            
            return response()->json([
                'message' => 'Sucursal actualizada exitosamente',
                'data' => $sucursal
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la sucursal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $sucursal = Sucursal::findOrFail($id);
            $sucursal->delete();
            
            return response()->json([
                'message' => 'Sucursal eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar la sucursal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sucursales por empresa
     */
    public function porEmpresa(string $empresaId): JsonResponse
    {
        try {
            $sucursales = Sucursal::with('empresa')
                ->where('id_Empresa', $empresaId)
                ->orderBy('nombre')
                ->get();
            
            return response()->json($sucursales, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las sucursales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function normalizarPrefijo(?string $prefijo): ?string
    {
        $prefijo = trim((string) $prefijo);

        return $prefijo === '' ? null : strtoupper($prefijo);
    }
}
