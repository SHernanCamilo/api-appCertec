<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PermisoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            // Verificar si la tabla existe
            if (!DB::getSchemaBuilder()->hasTable('seg_permisos')) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tabla de permisos no existe aún',
                    'data' => []
                ]);
            }

            $query = DB::table('seg_permisos')
                ->leftJoin('seg_modulos', 'seg_permisos.id_modulo', '=', 'seg_modulos.id')
                ->select(
                    'seg_permisos.*',
                    'seg_modulos.nombre as modulo_nombre',
                    'seg_modulos.codigo as modulo_codigo'
                );

            // Filtrar por módulo si se proporciona
            if ($request->has('id_modulo')) {
                $query->where('seg_permisos.id_modulo', $request->id_modulo);
            }

            // Filtrar por tipo si se proporciona
            if ($request->has('tipo')) {
                $query->where('seg_permisos.tipo', $request->tipo);
            }

            // Filtrar por estado si se proporciona
            if ($request->has('estado')) {
                $query->where('seg_permisos.estado', $request->estado);
            }

            $permisos = $query->orderBy('seg_permisos.orden')->get();

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos exitosamente',
                'data' => $permisos
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en PermisoController@index: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_modulo' => 'required|exists:seg_modulos,id',
            'nombre' => 'required|string|max:100',
            'codigo' => 'required|string|max:50|unique:seg_permisos,codigo',
            'descripcion' => 'nullable|string',
            'tipo' => 'required|in:boton,accion,menu',
            'icono' => 'nullable|string|max:50',
            'orden' => 'nullable|integer',
            'estado' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $permiso = DB::table('seg_permisos')->insertGetId([
                'id_modulo' => $request->id_modulo,
                'nombre' => $request->nombre,
                'codigo' => $request->codigo,
                'descripcion' => $request->descripcion,
                'tipo' => $request->tipo,
                'icono' => $request->icono,
                'orden' => $request->orden ?? 0,
                'estado' => $request->estado ?? true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $permisoCreado = DB::table('seg_permisos')->where('id', $permiso)->first();

            return response()->json([
                'success' => true,
                'message' => 'Permiso creado exitosamente',
                'data' => $permisoCreado
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $permiso = DB::table('seg_permisos')
                ->join('seg_modulos', 'seg_permisos.id_modulo', '=', 'seg_modulos.id')
                ->select(
                    'seg_permisos.*',
                    'seg_modulos.nombre as modulo_nombre',
                    'seg_modulos.codigo as modulo_codigo'
                )
                ->where('seg_permisos.id', $id)
                ->first();

            if (!$permiso) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permiso no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permiso obtenido exitosamente',
                'data' => $permiso
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'id_modulo' => 'sometimes|required|exists:seg_modulos,id',
            'nombre' => 'sometimes|required|string|max:100',
            'codigo' => 'sometimes|required|string|max:50|unique:seg_permisos,codigo,' . $id,
            'descripcion' => 'nullable|string',
            'tipo' => 'sometimes|required|in:boton,accion,menu',
            'icono' => 'nullable|string|max:50',
            'orden' => 'nullable|integer',
            'estado' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $permiso = DB::table('seg_permisos')->where('id', $id)->first();

            if (!$permiso) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permiso no encontrado'
                ], 404);
            }

            $dataToUpdate = array_filter($request->only([
                'id_modulo', 'nombre', 'codigo', 'descripcion', 
                'tipo', 'icono', 'orden', 'estado'
            ]), function($value) {
                return $value !== null;
            });

            $dataToUpdate['updated_at'] = now();

            DB::table('seg_permisos')->where('id', $id)->update($dataToUpdate);

            $permisoActualizado = DB::table('seg_permisos')->where('id', $id)->first();

            return response()->json([
                'success' => true,
                'message' => 'Permiso actualizado exitosamente',
                'data' => $permisoActualizado
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $permiso = DB::table('seg_permisos')->where('id', $id)->first();

            if (!$permiso) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permiso no encontrado'
                ], 404);
            }

            DB::table('seg_permisos')->where('id', $id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Permiso eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos agrupados por módulo
     */
    public function porModulo()
    {
        try {
            $modulos = DB::table('seg_modulos')
                ->where('estado', 1)
                ->orderBy('orden')
                ->get();

            $resultado = [];

            foreach ($modulos as $modulo) {
                $permisos = DB::table('seg_permisos')
                    ->where('id_modulo', $modulo->id)
                    ->orderBy('orden')
                    ->get();

                $resultado[] = [
                    'modulo' => $modulo,
                    'permisos' => $permisos,
                    'total_permisos' => count($permisos),
                    'permisos_activos' => $permisos->where('estado', 1)->count()
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Permisos por módulo obtenidos exitosamente',
                'data' => $resultado
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos por módulo: ' . $e->getMessage()
            ], 500);
        }
    }
}
