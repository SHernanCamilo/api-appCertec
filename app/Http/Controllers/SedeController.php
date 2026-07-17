<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SedeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Sede::with(['sucursal.empresa']);
            
            // Filtrar por sucursal si se proporciona el parámetro
            if ($request->has('id_Sucursal')) {
                $query->where('id_Sucursal', $request->id_Sucursal);
            }
            
            // Filtrar por empresa si se proporciona el parámetro
            if ($request->has('id_Empresa')) {
                $query->whereHas('sucursal', function($q) use ($request) {
                    $q->where('id_Empresa', $request->id_Empresa);
                });
            }
            
            $sedes = $query->orderBy('created_at', 'desc')->get();
            
            return response()->json($sedes, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las sedes',
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
            'id_Sucursal' => 'required|integer|exists:config_ubi_sucursales,id'
        ], [
            'nombre.required' => 'El nombre de la sede es obligatorio',
            'nombre.max' => 'El nombre no puede exceder 50 caracteres',
            'prefijo.max' => 'El prefijo no puede exceder 10 caracteres',
            'id_Sucursal.required' => 'Debe seleccionar una sucursal',
            'id_Sucursal.exists' => 'La sucursal seleccionada no existe'
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
            $sede = Sede::create($data);
            $sede->load(['sucursal.empresa']);
            
            return response()->json([
                'message' => 'Sede creada exitosamente',
                'data' => $sede
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la sede',
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
            $sede = Sede::with(['sucursal.empresa'])->findOrFail($id);
            
            return response()->json($sede, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Sede no encontrada',
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
            'id_Sucursal' => 'sometimes|required|integer|exists:config_ubi_sucursales,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $sede = Sede::findOrFail($id);
            $data = $validator->validated();
            if (array_key_exists('prefijo', $data)) {
                $data['prefijo'] = $this->normalizarPrefijo($data['prefijo']);
            }
            $sede->update($data);
            $sede->load(['sucursal.empresa']);
            
            return response()->json([
                'message' => 'Sede actualizada exitosamente',
                'data' => $sede
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la sede',
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
            $sede = Sede::findOrFail($id);
            $sede->delete();
            
            return response()->json([
                'message' => 'Sede eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar la sede',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sedes por sucursal
     */
    public function porSucursal(string $sucursalId): JsonResponse
    {
        try {
            $sedes = Sede::with(['sucursal.empresa'])
                ->where('id_Sucursal', $sucursalId)
                ->orderBy('nombre')
                ->get();
            
            return response()->json($sedes, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las sedes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sedes por empresa
     */
    public function porEmpresa(string $empresaId): JsonResponse
    {
        try {
            $sedes = Sede::with(['sucursal.empresa'])
                ->whereHas('sucursal', function($q) use ($empresaId) {
                    $q->where('id_Empresa', $empresaId);
                })
                ->orderBy('nombre')
                ->get();
            
            return response()->json($sedes, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las sedes',
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
