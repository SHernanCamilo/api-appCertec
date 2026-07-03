<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\CtFestivo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

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

            $festivos = $query->orderBy('fecha')->get()->map(fn ($f) => [
                'id'          => $f->id,
                'fecha'       => $f->fecha->format('Y-m-d'),
                'nombre'      => $f->nombre,
                'descripcion' => $f->descripcion,
                'estado'      => $f->estado,
            ]);

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

    /**
     * POST /api/turnos/festivos/sincronizar
     * Importa festivos de Colombia desde Nager.Date (API pública, sin clave).
     */
    public function sincronizar(Request $request): JsonResponse
    {
        $anio = (int) $request->input('anio', date('Y'));
        $pais = strtoupper($request->input('pais', config('talento_humano.festivos_pais', 'CO')));

        try {
            $response = Http::timeout(15)->get("https://date.nager.at/api/v3/PublicHolidays/{$anio}/{$pais}");

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo consultar la API de festivos (Nager.Date).',
                ], 502);
            }

            $importados = 0;
            foreach ($response->json() as $item) {
                CtFestivo::updateOrCreate(
                    ['fecha' => $item['date']],
                    [
                        'nombre'      => $item['localName'] ?? $item['name'],
                        'descripcion' => "Sincronizado Nager.Date ({$pais}) — " . ($item['name'] ?? ''),
                        'estado'      => true,
                    ]
                );
                $importados++;
            }

            return response()->json([
                'success' => true,
                'message' => "Sincronizados {$importados} festivos para {$anio}.",
                'data'    => ['anio' => $anio, 'total' => $importados],
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
     */
    public function testConexion(): JsonResponse
    {
        try {
            $response = Http::timeout(10)->get('https://date.nager.at/api/v3/AvailableCountries');
            $co = collect($response->json())->firstWhere('countryCode', 'CO');

            return response()->json([
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Conexión OK con Nager.Date (Colombia disponible: ' . ($co ? 'sí' : 'no') . ').'
                    : 'Sin respuesta de Nager.Date.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
