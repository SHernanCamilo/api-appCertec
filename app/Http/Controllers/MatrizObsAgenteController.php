<?php

namespace App\Http\Controllers;

use App\Models\MatrizObsAgente;
use App\Models\MatrizObsParametro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MatrizObsAgenteController extends Controller
{
    /**
     * Obtener todos los agentes
     */
    public function index()
    {
        try {
            $agentes = MatrizObsAgente::with(['empresa', 'sucursal', 'sede'])->get();
            
            return response()->json([
                'success' => true,
                'data' => $agentes
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los agentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo agente
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tag' => 'required|string|max:100|unique:matzobs_agentes,tag',
            'id_empresa' => 'required|exists:ent_empresas,id',
            'id_sucursal' => 'required|exists:config_ubi_sucursales,id',
            'id_sede' => 'nullable|exists:config_ubi_sede,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $agente = MatrizObsAgente::create($request->all());
            $agente->load(['empresa', 'sucursal', 'sede']);
            
            return response()->json([
                'success' => true,
                'message' => 'Agente creado exitosamente',
                'data' => $agente
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un agente específico
     */
    public function show($id)
    {
        try {
            $agente = MatrizObsAgente::with(['empresa', 'sucursal', 'sede'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $agente
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Agente no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualizar un agente
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'tag' => 'sometimes|required|string|max:100|unique:matzobs_agentes,tag,' . $id,
            'id_empresa' => 'sometimes|required|exists:ent_empresas,id',
            'id_sucursal' => 'sometimes|required|exists:config_ubi_sucursales,id',
            'id_sede' => 'nullable|exists:config_ubi_sede,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $agente = MatrizObsAgente::findOrFail($id);
            $agente->update($request->all());
            $agente->load(['empresa', 'sucursal', 'sede']);
            
            return response()->json([
                'success' => true,
                'message' => 'Agente actualizado exitosamente',
                'data' => $agente
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un agente
     */
    public function destroy($id)
    {
        try {
            $agente = MatrizObsAgente::findOrFail($id);
            $agente->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Agente eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el agente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener parámetro de sincronización de equipos (id_grupo = 5)
     */
    public function getSincronizacionParametro()
    {
        try {
            $parametro = MatrizObsParametro::where('id_grupo', 5)
                ->where('nombre', 'like', '%sincronizacion%')
                ->first();
            
            if (!$parametro) {
                // Si no existe, crear uno por defecto
                $parametro = MatrizObsParametro::create([
                    'id_grupo' => 5,
                    'nombre' => 'Sincronización de equipos',
                    'valor' => '24', // 24 horas por defecto
                    'frecuencia' => 'horas'
                ]);
            }
            
            return response()->json([
                'success' => true,
                'data' => $parametro
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el parámetro de sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar parámetro de sincronización de equipos - Versión simplificada
     */
    public function updateSincronizacionParametro(Request $request)
    {
        try {
            // Validación simple
            if (!$request->has('valor') || empty($request->valor)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El valor es requerido'
                ], 400);
            }

            // Buscar el parámetro existente
            $parametro = MatrizObsParametro::where('id_grupo', 5)
                ->where('nombre', 'FRECUENCIA DE SINCRONIZACION')
                ->first();
            
            if ($parametro) {
                // Actualizar el existente
                $parametro->valor = $request->valor;
                $parametro->save();
            } else {
                // Crear nuevo si no existe
                $parametro = new MatrizObsParametro();
                $parametro->id_grupo = 5;
                $parametro->nombre = 'FRECUENCIA DE SINCRONIZACION';
                $parametro->valor = $request->valor;
                $parametro->frecuencia = 'Semestral';
                $parametro->save();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro actualizado correctamente',
                'data' => $parametro
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
