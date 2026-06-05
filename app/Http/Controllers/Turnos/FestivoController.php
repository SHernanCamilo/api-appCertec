<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\CtFestivo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FestivoController extends Controller
{
    /**
     * GET /api/turnos/festivos
     * Query opcional: anio, desde, hasta
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtFestivo::query()->activos();

            if ($request->filled('anio')) {
                $query->whereYear('fecha', (int) $request->anio);
            }

            if ($request->filled('desde') && $request->filled('hasta')) {
                $query->whereBetween('fecha', [$request->desde, $request->hasta]);
            }

            $festivos = $query->orderBy('fecha')->get();

            return response()->json([
                'success' => true,
                'data'    => $festivos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener festivos: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'fecha'       => 'required|date|unique:humtal_ct_festivos,fecha',
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'estado'      => 'boolean',
        ]);

        try {
            $festivo = CtFestivo::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Festivo creado.',
                'data'    => $festivo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear festivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'fecha'       => "date|unique:humtal_ct_festivos,fecha,{$id}",
            'nombre'      => 'string|max:150',
            'descripcion' => 'nullable|string',
            'estado'      => 'boolean',
        ]);

        try {
            $festivo = CtFestivo::findOrFail($id);
            $festivo->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Festivo actualizado.',
                'data'    => $festivo->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar festivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $festivo = CtFestivo::findOrFail($id);
            $festivo->update(['estado' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Festivo desactivado.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar festivo: ' . $e->getMessage(),
            ], 500);
        }
    }
}
