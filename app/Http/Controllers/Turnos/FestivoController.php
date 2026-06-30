<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\CtFestivo;
use App\Services\FestivosComCoProvider;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class FestivoController extends Controller
{
    private FestivosComCoProvider $festivosProvider;

    public function __construct(FestivosComCoProvider $festivosProvider)
    {
        $this->festivosProvider = $festivosProvider;
    }

    /**
     * GET /api/turnos/festivos
     * Query opcional: anio, desde, hasta
     * Prioriza festivos locales, con opción de sincronizar desde API externa
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $anio = (int) $request->query('anio', Carbon::now()->year);
            $sincronizar = $request->boolean('sincronizar', false);

            // Si solicita sincronización, obtener de API externa y actualizar BD
            if ($sincronizar) {
                $this->sincronizarFestivos($anio);
            }

            // Obtener festivos de BD local
            $query = CtFestivo::query()->activos();

            if ($anio) {
                $query->whereYear('fecha', $anio);
            }

            if ($request->filled('desde') && $request->filled('hasta')) {
                $query->whereBetween('fecha', [$request->desde, $request->hasta]);
            }

            $festivos = $query->orderBy('fecha')->get()->map(function ($f) {
                return [
                    'fecha' => $f->fecha,
                    'nombre' => $f->nombre,
                    'tipo' => $f->tipo ?? 'festivo',
                    'origen' => $f->origen ?? 'local'
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $festivos,
                'meta' => [
                    'anio' => $anio,
                    'total' => $festivos->count(),
                    'sincronizado' => $sincronizar
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener festivos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/festivos/sincronizar
     * Sincroniza festivos desde API externa hacia BD local
     */
    public function sincronizar(Request $request): JsonResponse
    {
        $request->validate([
            'anio' => 'required|integer|min:2020|max:2030'
        ]);

        try {
            $anio = (int) $request->anio;
            $resultado = $this->sincronizarFestivos($anio);

            return response()->json([
                'success' => true,
                'message' => "Festivos sincronizados para {$anio}",
                'data' => $resultado
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar festivos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/festivos/test-conexion
     * Probar conectividad con API externa
     */
    public function testConexion(): JsonResponse
    {
        try {
            $resultado = $this->festivosProvider->testConexion();

            return response()->json([
                'success' => $resultado['success'],
                'message' => $resultado['message'],
                'data' => $resultado['data'] ?? null
            ], $resultado['success'] ? 200 : 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en test de conexión: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar festivos de un año desde API externa hacia BD local
     */
    private function sincronizarFestivos(int $anio): array
    {
        $festivosExternos = $this->festivosProvider->obtenerFestivos($anio);
        
        if (empty($festivosExternos)) {
            throw new \Exception("No se obtuvieron festivos de la API externa para {$anio}");
        }

        $insertados = 0;
        $actualizados = 0;

        foreach ($festivosExternos as $festivoExterno) {
            $festivo = CtFestivo::updateOrCreate(
                ['fecha' => $festivoExterno['fecha']],
                [
                    'nombre' => $festivoExterno['nombre'],
                    'tipo' => $festivoExterno['tipo'] ?? 'festivo',
                    'origen' => 'api_externa',
                    'estado' => true,
                    'descripcion' => "Sincronizado desde API externa el " . Carbon::now()->format('Y-m-d H:i:s')
                ]
            );

            if ($festivo->wasRecentlyCreated) {
                $insertados++;
            } else {
                $actualizados++;
            }
        }

        return [
            'anio' => $anio,
            'total_obtenidos' => count($festivosExternos),
            'insertados' => $insertados,
            'actualizados' => $actualizados,
            'sincronizado_at' => Carbon::now()->toISOString()
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'fecha'       => 'required|date|unique:humtal_ct_festivos,fecha',
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'tipo'        => 'nullable|string|max:50',
            'estado'      => 'boolean',
        ]);

        try {
            $data = $request->all();
            $data['origen'] = 'manual';

            $festivo = CtFestivo::create($data);

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
            'tipo'        => 'nullable|string|max:50',
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
