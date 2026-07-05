<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Modulo;
use App\Services\SidebarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuloController extends Controller
{
    public function __construct(
        private SidebarService $sidebarService
    ) {}
    /**
     * Listar todos los m?dulos con estructura jer?rquica
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
                'message' => 'Error al obtener m?dulos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estructura de ?rbol completa
     */
    public function tree()
    {
        try {
            // Cargar m?dulos ra?z con todos sus hijos recursivamente
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
                'message' => 'Error al obtener ?rbol de m?dulos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo m?dulo
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
                    'message' => 'Errores de validaci?n',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Calcular nivel autom?ticamente
            if (isset($data['id_modulo_padre'])) {
                $padre = Modulo::find($data['id_modulo_padre']);
                $data['nivel'] = $padre->nivel + 1;
            } else {
                $data['nivel'] = 0;
            }

            $modulo = Modulo::create($data);

            $this->sidebarService->invalidateAllSidebarCache();

            return response()->json([
                'success' => true,
                'message' => 'M?dulo creado exitosamente',
                'data' => $modulo
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear m?dulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un m?dulo espec?fico
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
                'message' => 'M?dulo no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualizar un m?dulo
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
                    'message' => 'Errores de validaci?n',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            // Recalcular nivel si cambi? el padre
            if (isset($data['id_modulo_padre'])) {
                if ($data['id_modulo_padre']) {
                    $padre = Modulo::find($data['id_modulo_padre']);
                    $data['nivel'] = $padre->nivel + 1;
                } else {
                    $data['nivel'] = 0;
                }
            }

            $modulo->update($data);

            $this->sidebarService->invalidateAllSidebarCache();

            return response()->json([
                'success' => true,
                'message' => 'M?dulo actualizado exitosamente',
                'data' => $modulo
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar m?dulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un m?dulo
     */
    public function destroy($id)
    {
        try {
            $modulo = Modulo::findOrFail($id);

            // Verificar si tiene hijos
            if ($modulo->hijos()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar un m?dulo que tiene subm?dulos'
                ], 400);
            }

            $modulo->delete();

            $this->sidebarService->invalidateAllSidebarCache();

            return response()->json([
                'success' => true,
                'message' => 'M?dulo eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar m?dulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
