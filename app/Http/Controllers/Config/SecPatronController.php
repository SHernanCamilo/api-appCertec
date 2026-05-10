<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\Config\SecPatron;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SecPatronController extends Controller
{
    /**
     * Listar patrones de la empresa del contexto actual.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $empresaId = $request->query('empresa_id');

            $query = SecPatron::query()->with('creador:id,name');

            if ($empresaId) {
                $query->where('empresa_id', $empresaId);
            }

            if ($request->boolean('solo_activos', false)) {
                $query->activos();
            }

            $patrones = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data'    => $patrones,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los patrones de secuencia',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear un nuevo patrón.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'empresa_id'  => 'required|integer|exists:ent_empresas,id',
            'nombre'      => 'required|string|max:100',
            'patron'      => 'required|string|max:50',
            'descripcion' => 'nullable|string|max:255',
            'estado'      => 'nullable|boolean',
        ], [
            'empresa_id.required' => 'La empresa es obligatoria',
            'empresa_id.exists'   => 'La empresa no existe',
            'nombre.required'     => 'El nombre del patrón es obligatorio',
            'patron.required'     => 'El patrón es obligatorio',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Verificar unicidad nombre + empresa
        $existe = SecPatron::where('empresa_id', $request->empresa_id)
            ->where('nombre', $request->nombre)
            ->whereNull('deleted_at')
            ->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe un patrón con ese nombre para esta empresa',
            ], 422);
        }

        try {
            $patron = SecPatron::create([
                'empresa_id'  => $request->empresa_id,
                'nombre'      => $request->nombre,
                'patron'      => $request->patron,
                'descripcion' => $request->descripcion,
                'estado'      => $request->input('estado', true),
                'created_by'  => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Patrón creado correctamente',
                'data'    => $patron,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el patrón',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar un patrón específico.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $patron = SecPatron::with('creador:id,name')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $patron,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Patrón no encontrado',
            ], 404);
        }
    }

    /**
     * Actualizar un patrón.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $patron = SecPatron::find($id);

        if (!$patron) {
            return response()->json([
                'success' => false,
                'message' => 'Patrón no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre'      => 'sometimes|required|string|max:100',
            'patron'      => 'sometimes|required|string|max:50',
            'descripcion' => 'nullable|string|max:255',
            'estado'      => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Verificar unicidad si cambia el nombre
        if ($request->has('nombre') && $request->nombre !== $patron->nombre) {
            $existe = SecPatron::where('empresa_id', $patron->empresa_id)
                ->where('nombre', $request->nombre)
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un patrón con ese nombre para esta empresa',
                ], 422);
            }
        }

        try {
            $patron->update($request->only(['nombre', 'patron', 'descripcion', 'estado']));

            return response()->json([
                'success' => true,
                'message' => 'Patrón actualizado correctamente',
                'data'    => $patron->fresh(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el patrón',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar (soft delete) un patrón.
     */
    public function destroy(int $id): JsonResponse
    {
        $patron = SecPatron::find($id);

        if (!$patron) {
            return response()->json([
                'success' => false,
                'message' => 'Patrón no encontrado',
            ], 404);
        }

        // Verificar que no esté en uso
        if ($patron->detalles()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el patrón porque está siendo usado en detalles de secuencia',
            ], 409);
        }

        try {
            $patron->delete();

            return response()->json([
                'success' => true,
                'message' => 'Patrón eliminado correctamente',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el patrón',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
