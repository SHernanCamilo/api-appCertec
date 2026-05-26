<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\Config\SecDetalle;
use App\Models\Config\SecSecuencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SecDetalleController extends Controller
{
    /**
     * Listar detalles de una secuencia.
     */
    public function index(int $secuenciaId): JsonResponse
    {
        try {
            $secuencia = SecSecuencia::find($secuenciaId);

            if (!$secuencia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Secuencia no encontrada',
                ], 404);
            }

            $detalles = SecDetalle::with([
                'patron',
                'sucursal:id,nombre',
                'sede:id,nombre',
            ])
                ->where('secuencia_id', $secuenciaId)
                ->orderBy('id')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $detalles,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los detalles',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Agregar un detalle a una secuencia existente.
     */
    public function store(Request $request, int $secuenciaId): JsonResponse
    {
        $secuencia = SecSecuencia::find($secuenciaId);

        if (!$secuencia) {
            return response()->json([
                'success' => false,
                'message' => 'Secuencia no encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'patron_id'        => 'required|integer|exists:config_sec_patrones,id',
            'sucursal_id'      => 'nullable|integer|exists:config_ubi_sucursales,id',
            'sede_id'          => 'nullable|integer|exists:config_ubi_sede,id',
            'siguiente_numero' => 'nullable|integer|min:1',
        ], [
            'patron_id.required' => 'El patrón es obligatorio',
            'patron_id.exists'   => 'El patrón seleccionado no existe',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $detalle = SecDetalle::create([
                'secuencia_id'    => $secuenciaId,
                'patron_id'       => $request->patron_id,
                'sucursal_id'     => $request->sucursal_id,
                'sede_id'         => $request->sede_id,
                'siguiente_numero' => $request->input('siguiente_numero', 1),
                'estado'          => true,
                'created_by'      => Auth::id(),
            ]);

            $detalle->load(['patron', 'sucursal:id,nombre', 'sede:id,nombre']);

            return response()->json([
                'success' => true,
                'message' => 'Detalle agregado correctamente',
                'data'    => $detalle,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar el detalle',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar un detalle (patrón, siguiente número, estado).
     */
    public function update(Request $request, int $secuenciaId, int $detalleId): JsonResponse
    {
        $detalle = SecDetalle::where('secuencia_id', $secuenciaId)->find($detalleId);

        if (!$detalle) {
            return response()->json([
                'success' => false,
                'message' => 'Detalle no encontrado',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'patron_id'        => 'sometimes|required|integer|exists:config_sec_patrones,id',
            'siguiente_numero' => 'sometimes|required|integer|min:1',
            'estado'           => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $detalle->update($request->only(['patron_id', 'siguiente_numero', 'estado']));

            $detalle->load(['patron', 'sucursal:id,nombre', 'sede:id,nombre']);

            return response()->json([
                'success' => true,
                'message' => 'Detalle actualizado correctamente',
                'data'    => $detalle,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el detalle',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar (soft delete) un detalle.
     */
    public function destroy(int $secuenciaId, int $detalleId): JsonResponse
    {
        $detalle = SecDetalle::where('secuencia_id', $secuenciaId)->find($detalleId);

        if (!$detalle) {
            return response()->json([
                'success' => false,
                'message' => 'Detalle no encontrado',
            ], 404);
        }

        try {
            $detalle->delete();

            return response()->json([
                'success' => true,
                'message' => 'Detalle eliminado correctamente',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el detalle',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
