<?php

namespace App\Http\Controllers\TalentoHumano;

use App\Http\Controllers\Controller;
use App\Services\TalentoHumano\EventNovedadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventNovedadController extends Controller
{
    public function __construct(
        private readonly EventNovedadService $service
    ) {}

    // ─── Catálogo ─────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->service->listar($request->all()),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar novedades', $e);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->service->obtener($id),
            ]);
        } catch (\Exception $e) {
            return $this->error('Novedad no encontrada', $e, 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'      => 'required|string|max:20|unique:event_novedades,codigo',
            'descripcion' => 'required|string|max:150',
            'cubre'       => 'boolean',
            'activo'      => 'boolean',
        ]);

        try {
            $novedad = $this->service->crear($request->all());
            return response()->json([
                'success' => true,
                'message' => 'Novedad creada correctamente',
                'data'    => $novedad,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Error al crear la novedad', $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo'      => "string|max:20|unique:event_novedades,codigo,{$id}",
            'descripcion' => 'string|max:150',
            'cubre'       => 'boolean',
            'activo'      => 'boolean',
        ]);

        try {
            $novedad = $this->service->actualizar($id, $request->all());
            return response()->json([
                'success' => true,
                'message' => 'Novedad actualizada correctamente',
                'data'    => $novedad,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al actualizar la novedad', $e);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->eliminar($id);
            return response()->json([
                'success' => true,
                'message' => 'Novedad eliminada correctamente',
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al eliminar la novedad', $e);
        }
    }

    // ─── Vinculaciones ────────────────────────────────────────────────────────

    public function vinculaciones(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->service->listarVinculaciones($request->all()),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar vinculaciones', $e);
        }
    }

    public function vincular(Request $request): JsonResponse
    {
        $request->validate([
            'novedad_id' => 'required|exists:event_novedades,id',
            'empresa_id' => 'nullable|exists:ent_empresas,id',
            'cargo_id'   => 'nullable|exists:config_cargo,id_cargo',
            'activo'     => 'boolean',
        ]);

        try {
            $vinculacion = $this->service->vincular($request->all());
            return response()->json([
                'success' => true,
                'message' => 'Vinculación creada correctamente',
                'data'    => $vinculacion,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Error al vincular la novedad', $e);
        }
    }

    public function desvincular(int $id): JsonResponse
    {
        try {
            $this->service->desvincular($id);
            return response()->json([
                'success' => true,
                'message' => 'Vinculación eliminada correctamente',
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al desvincular', $e);
        }
    }

    public function novedadesAplicables(Request $request): JsonResponse
    {
        $request->validate([
            'empresa_id' => 'required|integer',
            'cargo_id'   => 'required|integer',
        ]);

        try {
            return response()->json([
                'success' => true,
                'data'    => $this->service->novedadesAplicables(
                    $request->integer('empresa_id'),
                    $request->integer('cargo_id')
                ),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al obtener novedades aplicables', $e);
        }
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    public function getCargos(): JsonResponse
    {
        try {
            $cargos = \App\Models\Cargo::select('id_cargo', 'nombre_cargo')
                ->orderBy('nombre_cargo')
                ->get();

            return response()->json(['success' => true, 'data' => $cargos]);
        } catch (\Exception $e) {
            return $this->error('Error al obtener cargos', $e);
        }
    }

    private function error(string $message, \Exception $e, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], $status);
    }
}
