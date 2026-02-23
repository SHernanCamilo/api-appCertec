<?php

namespace App\Http\Controllers\MatrizObsolescencia;

use App\Http\Controllers\Controller;
use App\Models\MatrizObsolescencia\MatzobsProcesador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProcesadorController extends Controller
{
    /**
     * Listar todos los procesadores
     */
    public function index(Request $request)
    {
        try {
            $query = MatzobsProcesador::query();

            // Filtro por búsqueda
            if ($request->has('search') && $request->search) {
                $query->buscarPorNombre($request->search);
            }

            // Ordenar
            $query->orderBy('nombre', 'asc');

            $procesadores = $query->get();

            return response()->json([
                'success' => true,
                'data' => $procesadores
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener procesadores: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los procesadores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un procesador específico
     */
    public function show($id)
    {
        try {
            $procesador = MatzobsProcesador::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $procesador
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener procesador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Procesador no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Crear un nuevo procesador
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:200',
            'anio_lanzamiento' => 'nullable|integer|min:1970|max:' . (date('Y') + 5),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $procesador = MatzobsProcesador::create([
                'nombre' => $request->nombre,
                'anio_lanzamiento' => $request->anio_lanzamiento,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Procesador creado exitosamente',
                'data' => $procesador
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear procesador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el procesador',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un procesador existente
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:200',
            'anio_lanzamiento' => 'nullable|integer|min:1970|max:' . (date('Y') + 5),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $procesador = MatzobsProcesador::findOrFail($id);

            $procesador->update([
                'nombre' => $request->nombre,
                'anio_lanzamiento' => $request->anio_lanzamiento,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Procesador actualizado exitosamente',
                'data' => $procesador
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar procesador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el procesador',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un procesador
     */
    public function destroy($id)
    {
        try {
            $procesador = MatzobsProcesador::findOrFail($id);
            $procesador->delete();

            return response()->json([
                'success' => true,
                'message' => 'Procesador eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar procesador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el procesador',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener procesadores únicos desde matzobs_activos_d
     */
    public function getProcesadoresDesdeActivos()
    {
        try {
            $procesadores = \DB::table('matzobs_activos_d')
                ->select('procesador')
                ->whereNotNull('procesador')
                ->where('procesador', '!=', '')
                ->distinct()
                ->orderBy('procesador')
                ->pluck('procesador');

            return response()->json([
                'success' => true,
                'data' => $procesadores
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener procesadores desde activos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener procesadores desde activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Importar procesadores desde matzobs_activos_d
     */
    public function importarProcesadoresDesdeActivos()
    {
        try {
            $procesadoresActivos = \DB::table('matzobs_activos_d')
                ->select('procesador')
                ->whereNotNull('procesador')
                ->where('procesador', '!=', '')
                ->distinct()
                ->pluck('procesador');

            $importados = 0;
            $duplicados = 0;

            foreach ($procesadoresActivos as $nombreProcesador) {
                // Verificar si ya existe
                $existe = MatzobsProcesador::where('nombre', $nombreProcesador)->exists();
                
                if (!$existe) {
                    MatzobsProcesador::create([
                        'nombre' => $nombreProcesador,
                        'anio_lanzamiento' => null
                    ]);
                    $importados++;
                } else {
                    $duplicados++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Procesadores importados exitosamente',
                'data' => [
                    'importados' => $importados,
                    'duplicados' => $duplicados,
                    'total' => $procesadoresActivos->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error al importar procesadores: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al importar procesadores',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
