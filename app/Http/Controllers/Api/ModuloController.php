<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Modulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuloController extends Controller
{
    /**
     * Listar todos los módulos con estructura jerárquica
     */
    public function index(Request $request)
    {
        try {
            $soloRaiz = $request->query('solo_raiz', false);
            $conHijos = $request->query('con_hijos', true);

            if ($soloRaiz) {
                $modulos = Modulo::raiz()->activos()->orderBy('orden')->get();
            } else {
                $modulos = Modulo::activos()->orderBy('nivel')->orderBy('orden')->get();
            }

            if ($conHijos && $soloRaiz) {
                $modulos->load('hijos.hijos');
            }

            return response()->json([
                'success' => true,
                'data' => $modulos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener módulos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estructura de árbol completa
     */
    public function tree()
    {
        try {
            // Cargar módulos raíz con todos sus hijos recursivamente
            $modulos = Modulo::raiz()
                ->activos()
                ->with(['hijos' => function($query) {
                    $query->orderBy('orden')->with(['hijos' => function($q) {
                        $q->orderBy('orden')->with(['hijos' => function($q2) {
                            $q2->orderBy('orden')->with(['hijos' => function($q3) {
                                $q3->orderBy('orden')->with('hijos');
                            }]);
                        }]);
                    }]);
                }])
                ->orderBy('orden')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $modulos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener árbol de módulos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo módulo
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:50',
                'codigo' => 'required|string|max:20|unique:seg_modulos,codigo',
                'descripcion' => 'nullable|string|max:255',
                'icono' => 'nullable|string|max:50',
                'ruta' => 'nullable|string|max:100',
                'orden' => 'nullable|integer',
                'id_modulo_padre' => 'nullable|exists:seg_modulos,id',
                'estado' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Calcular nivel automáticamente
            if (isset($data['id_modulo_padre'])) {
                $padre = Modulo::find($data['id_modulo_padre']);
                $data['nivel'] = $padre->nivel + 1;
            } else {
                $data['nivel'] = 0;
            }

            $modulo = Modulo::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Módulo creado exitosamente',
                'data' => $modulo
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear módulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un módulo específico
     */
    public function show($id)
    {
        try {
            $modulo = Modulo::with(['padre', 'hijos', 'empresas'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $modulo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Módulo no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualizar un módulo
     */
    public function update(Request $request, $id)
    {
        try {
            $modulo = Modulo::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'nombre' => 'sometimes|required|string|max:50',
                'codigo' => 'sometimes|required|string|max:20|unique:seg_modulos,codigo,' . $id,
                'descripcion' => 'nullable|string|max:255',
                'icono' => 'nullable|string|max:50',
                'ruta' => 'nullable|string|max:100',
                'orden' => 'nullable|integer',
                'id_modulo_padre' => 'nullable|exists:seg_modulos,id',
                'estado' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Recalcular nivel si cambió el padre
            if (isset($data['id_modulo_padre'])) {
                if ($data['id_modulo_padre']) {
                    $padre = Modulo::find($data['id_modulo_padre']);
                    $data['nivel'] = $padre->nivel + 1;
                } else {
                    $data['nivel'] = 0;
                }
            }

            $modulo->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Módulo actualizado exitosamente',
                'data' => $modulo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar módulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un módulo
     */
    public function destroy($id)
    {
        try {
            $modulo = Modulo::findOrFail($id);

            // Verificar si tiene hijos
            if ($modulo->hijos()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar un módulo que tiene submódulos'
                ], 400);
            }

            $modulo->delete();

            return response()->json([
                'success' => true,
                'message' => 'Módulo eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar módulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
