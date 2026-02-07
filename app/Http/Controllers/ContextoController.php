<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\UsuarioContexto;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\Sede;

class ContextoController extends Controller
{
    /**
     * Obtener contexto actual del usuario
     */
    public function obtenerContexto(): JsonResponse
    {
        $user = auth('api')->user();
        
        $contexto = UsuarioContexto::obtenerContexto($user);
        
        if (!$contexto) {
            // Si no tiene contexto, intentar asignar la primera empresa disponible
            $primeraEmpresa = $user->empresas()->first();
            
            if ($primeraEmpresa) {
                $contexto = UsuarioContexto::establecerContexto($user, $primeraEmpresa->id);
                $contexto->load(['empresa', 'sucursal', 'sede']);
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $contexto
        ]);
    }

    /**
     * Cambiar contexto del usuario
     */
    public function cambiarContexto(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        
        $request->validate([
            'empresa_id' => 'required|exists:ent_empresas,id',
            'sucursal_id' => 'nullable|exists:sucursals,id',
            'sede_id' => 'nullable|exists:sedes,id'
        ]);
        
        // Verificar que el usuario tiene acceso a la empresa
        if (!$user->empresas->contains($request->empresa_id)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a esta empresa'
            ], 403);
        }
        
        // Verificar que la sucursal pertenece a la empresa (si se proporciona)
        if ($request->sucursal_id) {
            $sucursal = Sucursal::find($request->sucursal_id);
            if (!$sucursal || $sucursal->id_Empresa != $request->empresa_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'La sucursal no pertenece a la empresa seleccionada'
                ], 400);
            }
        }
        
        // Verificar que la sede pertenece a la sucursal (si se proporciona)
        if ($request->sede_id) {
            if (!$request->sucursal_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Debes seleccionar una sucursal para asignar una sede'
                ], 400);
            }
            
            $sede = Sede::find($request->sede_id);
            if (!$sede || $sede->id_sucursal != $request->sucursal_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'La sede no pertenece a la sucursal seleccionada'
                ], 400);
            }
        }
        
        // Establecer el nuevo contexto
        $contexto = UsuarioContexto::establecerContexto(
            $user,
            $request->empresa_id,
            $request->sucursal_id,
            $request->sede_id
        );
        
        $contexto->load(['empresa', 'sucursal', 'sede']);
        
        return response()->json([
            'success' => true,
            'message' => 'Contexto actualizado exitosamente',
            'data' => $contexto
        ]);
    }

    /**
     * Obtener empresas disponibles para el usuario
     */
    public function obtenerEmpresasDisponibles(): JsonResponse
    {
        $user = auth('api')->user();
        
        $empresas = $user->empresas()
            ->where('estado', 1)
            ->with(['sucursales' => function($query) {
                $query->with('sedes');
            }])
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $empresas
        ]);
    }

    /**
     * Limpiar contexto del usuario
     */
    public function limpiarContexto(): JsonResponse
    {
        $user = auth('api')->user();
        
        UsuarioContexto::limpiarContexto($user);
        
        return response()->json([
            'success' => true,
            'message' => 'Contexto limpiado exitosamente'
        ]);
    }
}
