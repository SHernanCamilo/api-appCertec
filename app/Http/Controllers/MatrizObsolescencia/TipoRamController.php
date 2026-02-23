<?php

namespace App\Http\Controllers\MatrizObsolescencia;

use App\Http\Controllers\Controller;
use App\Models\MatrizObsolescencia\MatzobsTipoRam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TipoRamController extends Controller
{
    /**
     * Listar todos los tipos de RAM
     */
    public function index(Request $request)
    {
        try {
            $query = MatzobsTipoRam::query();

            // Filtro por búsqueda
            if ($request->has('search') && $request->search) {
                $query->buscarPorNombre($request->search);
            }

            // Ordenar
            $query->orderBy('nombre', 'asc');

            $tiposRam = $query->get();

            return response()->json([
                'success' => true,
                'data' => $tiposRam
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener tipos de RAM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los tipos de RAM',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un tipo de RAM específico
     */
    public function show($id)
    {
        try {
            $tipoRam = MatzobsTipoRam::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $tipoRam
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener tipo de RAM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Tipo de RAM no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Crear un nuevo tipo de RAM
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
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
            $tipoRam = MatzobsTipoRam::create([
                'nombre' => $request->nombre,
                'anio_lanzamiento' => $request->anio_lanzamiento,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tipo de RAM creado exitosamente',
                'data' => $tipoRam
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear tipo de RAM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el tipo de RAM',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un tipo de RAM existente
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
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
            $tipoRam = MatzobsTipoRam::findOrFail($id);

            $tipoRam->update([
                'nombre' => $request->nombre,
                'anio_lanzamiento' => $request->anio_lanzamiento,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tipo de RAM actualizado exitosamente',
                'data' => $tipoRam
            ]);
        } catch (\Exception $e) {
            Log::error('Error al actualizar tipo de RAM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el tipo de RAM',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un tipo de RAM
     */
    public function destroy($id)
    {
        try {
            $tipoRam = MatzobsTipoRam::findOrFail($id);
            $tipoRam->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tipo de RAM eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar tipo de RAM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el tipo de RAM',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener tipos de RAM únicos desde matzobs_activos_d
     */
    public function getTiposRamDesdeActivos()
    {
        try {
            $tiposRam = \DB::table('matzobs_activos_d')
                ->select('generacion_ram')
                ->whereNotNull('generacion_ram')
                ->where('generacion_ram', '!=', '')
                ->distinct()
                ->orderBy('generacion_ram')
                ->pluck('generacion_ram');

            return response()->json([
                'success' => true,
                'data' => $tiposRam
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener tipos de RAM desde activos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tipos de RAM desde activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Importar tipos de RAM desde matzobs_activos_d
     */
    public function importarTiposRamDesdeActivos()
    {
        try {
            $tiposRamActivos = \DB::table('matzobs_activos_d')
                ->select('generacion_ram')
                ->whereNotNull('generacion_ram')
                ->where('generacion_ram', '!=', '')
                ->distinct()
                ->pluck('generacion_ram');

            $importados = 0;
            $duplicados = 0;

            foreach ($tiposRamActivos as $nombreTipoRam) {
                // Verificar si ya existe
                $existe = MatzobsTipoRam::where('nombre', $nombreTipoRam)->exists();
                
                if (!$existe) {
                    MatzobsTipoRam::create([
                        'nombre' => $nombreTipoRam,
                        'anio_lanzamiento' => null
                    ]);
                    $importados++;
                } else {
                    $duplicados++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Tipos de RAM importados exitosamente',
                'data' => [
                    'importados' => $importados,
                    'duplicados' => $duplicados,
                    'total' => $tiposRamActivos->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error al importar tipos de RAM: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al importar tipos de RAM',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
