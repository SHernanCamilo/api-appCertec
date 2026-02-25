<?php

namespace App\Http\Controllers;

use App\Models\AntiTipo;
use App\Models\AntiClase;
use App\Models\AntiModalidad;
use App\Models\AntiConcepto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnticipoConceptoController extends Controller
{
    /**
     * GET /api/anticipos/tipos
     * Obtener todos los tipos de anticipos activos
     */
    public function getTipos()
    {
        try {
            $tipos = AntiTipo::activos()->orderBy('nombre')->get();
            
            return response()->json([
                'success' => true,
                'data' => $tipos
            ]);
        } catch (\Exception $e) {
            Log::error('Error obteniendo tipos de anticipos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los tipos de anticipos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/anticipos/tipos/{tipoId}/clases
     * Obtener clases por tipo de anticipo
     */
    public function getClasesPorTipo($tipoId)
    {
        try {
            $clases = AntiClase::where('id_tipo', $tipoId)
                ->where('estado', 1)
                ->orderBy('nombre')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $clases
            ]);
        } catch (\Exception $e) {
            Log::error('Error obteniendo clases por tipo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las clases',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/anticipos/clases/{claseId}/modalidades
     * Obtener modalidades por clase de anticipo
     */
    public function getModalidadesPorClase($claseId)
    {
        try {
            $modalidades = AntiModalidad::where('id_clase', $claseId)
                ->where('estado', 1)
                ->orderBy('nombre')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $modalidades
            ]);
        } catch (\Exception $e) {
            Log::error('Error obteniendo modalidades por clase: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las modalidades',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/anticipos/conceptos
     * Listar todos los conceptos con paginación
     */
    public function index(Request $request)
    {
        try {
            $query = AntiConcepto::with(['tipo', 'clase', 'modalidad', 'reglas']);
            
            // Filtros opcionales
            if ($request->has('tipo_id')) {
                $query->where('id_tipo', $request->tipo_id);
            }
            
            if ($request->has('clase_id')) {
                $query->where('id_clase', $request->clase_id);
            }
            
            if ($request->has('estado')) {
                $query->where('estado', $request->estado);
            }
            
            // Búsqueda
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->whereHas('tipo', function($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%");
                })->orWhereHas('clase', function($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%");
                })->orWhereHas('modalidad', function($q) use ($search) {
                    $q->where('nombre', 'like', "%{$search}%");
                });
            }
            
            $perPage = $request->per_page ?? 10;
            $conceptos = $query->orderBy('created_at', 'desc')->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $conceptos->items(),
                'total' => $conceptos->total(),
                'current_page' => $conceptos->currentPage(),
                'per_page' => $conceptos->perPage(),
                'last_page' => $conceptos->lastPage()
            ]);
        } catch (\Exception $e) {
            Log::error('Error listando conceptos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al listar los conceptos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * GET /api/anticipos/conceptos/{id}
     * Obtener un concepto específico
     */
    public function show($id)
    {
        try {
            $concepto = AntiConcepto::with(['tipo', 'clase', 'modalidad', 'reglas'])
                ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $concepto
            ]);
        } catch (\Exception $e) {
            Log::error('Error obteniendo concepto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Concepto no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * POST /api/anticipos/conceptos
     * Crear un nuevo concepto con sus reglas o agregar reglas a uno existente
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_tipo' => 'required|exists:anti_tipos,id',
            'id_clase' => 'required|exists:anti_clases,id',
            'id_modalidad' => 'required|exists:anti_modalidades,id',
            'estado' => 'boolean',
            'reglas' => 'required|array|min:1',
            'reglas.*.descripcion' => 'required|string|max:255',
            'reglas.*.valor_tope' => 'required|numeric|min:0'
        ]);
        
        DB::beginTransaction();
        try {
            // Buscar si ya existe el concepto
            $concepto = AntiConcepto::where('id_tipo', $validated['id_tipo'])
                ->where('id_clase', $validated['id_clase'])
                ->where('id_modalidad', $validated['id_modalidad'])
                ->first();
            
            if ($concepto) {
                // Si existe, agregar las nuevas reglas al concepto existente
                foreach ($validated['reglas'] as $regla) {
                    $concepto->reglas()->create([
                        'descripcion' => $regla['descripcion'],
                        'valor_tope' => $regla['valor_tope'],
                        'estado' => true
                    ]);
                }
                
                DB::commit();
                
                $concepto->load(['tipo', 'clase', 'modalidad', 'reglas']);
                
                Log::info('Reglas agregadas a concepto existente', ['concepto_id' => $concepto->id]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Reglas agregadas al concepto existente',
                    'data' => $concepto
                ], 200);
            }
            
            // Si no existe, crear concepto nuevo
            $concepto = AntiConcepto::create([
                'id_tipo' => $validated['id_tipo'],
                'id_clase' => $validated['id_clase'],
                'id_modalidad' => $validated['id_modalidad'],
                'estado' => $validated['estado'] ?? true
            ]);
            
            // Crear reglas
            foreach ($validated['reglas'] as $regla) {
                $concepto->reglas()->create([
                    'descripcion' => $regla['descripcion'],
                    'valor_tope' => $regla['valor_tope'],
                    'estado' => true
                ]);
            }
            
            DB::commit();
            
            $concepto->load(['tipo', 'clase', 'modalidad', 'reglas']);
            
            Log::info('Concepto de anticipo creado', ['concepto_id' => $concepto->id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Concepto creado correctamente',
                'data' => $concepto
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creando concepto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el concepto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * PUT /api/anticipos/conceptos/{id}
     * Actualizar un concepto existente
     */
    public function update(Request $request, $id)
    {
        $concepto = AntiConcepto::findOrFail($id);
        
        $validated = $request->validate([
            'id_tipo' => 'required|exists:anti_tipos,id',
            'id_clase' => 'required|exists:anti_clases,id',
            'id_modalidad' => 'required|exists:anti_modalidades,id',
            'estado' => 'boolean',
            'reglas' => 'required|array|min:1',
            'reglas.*.descripcion' => 'required|string|max:255',
            'reglas.*.valor_tope' => 'required|numeric|min:0'
        ]);
        
        DB::beginTransaction();
        try {
            // Verificar si ya existe otro concepto con la misma combinación
            $existente = AntiConcepto::where('id_tipo', $validated['id_tipo'])
                ->where('id_clase', $validated['id_clase'])
                ->where('id_modalidad', $validated['id_modalidad'])
                ->where('id', '!=', $id)
                ->first();
            
            if ($existente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe otro concepto con esta combinación de Tipo, Clase y Modalidad'
                ], 422);
            }
            
            // Actualizar concepto
            $concepto->update([
                'id_tipo' => $validated['id_tipo'],
                'id_clase' => $validated['id_clase'],
                'id_modalidad' => $validated['id_modalidad'],
                'estado' => $validated['estado'] ?? $concepto->estado
            ]);
            
            // Eliminar reglas antiguas y crear nuevas
            $concepto->reglas()->delete();
            foreach ($validated['reglas'] as $regla) {
                $concepto->reglas()->create([
                    'descripcion' => $regla['descripcion'],
                    'valor_tope' => $regla['valor_tope'],
                    'estado' => true
                ]);
            }
            
            DB::commit();
            
            $concepto->load(['tipo', 'clase', 'modalidad', 'reglas']);
            
            Log::info('Concepto de anticipo actualizado', ['concepto_id' => $concepto->id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Concepto actualizado correctamente',
                'data' => $concepto
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error actualizando concepto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el concepto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * DELETE /api/anticipos/conceptos/{id}
     * Eliminar un concepto
     */
    public function destroy($id)
    {
        try {
            $concepto = AntiConcepto::findOrFail($id);
            $concepto->delete();
            
            Log::info('Concepto de anticipo eliminado', ['concepto_id' => $id]);
            
            return response()->json([
                'success' => true,
                'message' => 'Concepto eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error eliminando concepto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el concepto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * PATCH /api/anticipos/conceptos/{id}/toggle-estado
     * Cambiar el estado de un concepto
     */
    public function toggleEstado($id)
    {
        try {
            $concepto = AntiConcepto::findOrFail($id);
            $concepto->estado = !$concepto->estado;
            $concepto->save();
            
            Log::info('Estado de concepto cambiado', [
                'concepto_id' => $id,
                'nuevo_estado' => $concepto->estado
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente',
                'data' => $concepto
            ]);
        } catch (\Exception $e) {
            Log::error('Error cambiando estado de concepto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
