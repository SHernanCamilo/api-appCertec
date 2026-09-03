<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\TipoInventario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Gestión de tipos de inventario para el módulo de activos fijos.
 *
 * Endpoints (prefijo /api/inventory/tipos-inventario):
 *   GET    /             → lista todos los tipos (activos e inactivos)
 *   POST   /             → crea un nuevo tipo
 *   PUT    /{id}         → actualiza un tipo existente
 *   DELETE /{id}         → elimina un tipo (soft delete o lógico)
 *   PATCH  /{id}/estado  → activa/desactiva un tipo
 */
class TiposInventarioController extends Controller
{
    /**
     * GET /api/inventory/tipos-inventario
     *
     * Lista todos los tipos de inventario con opciones de filtrado.
     *
     * Query params:
     *   ?activo=true        → solo activos
     *   ?periodicidad=anual → filtra por periodicidad específica
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'activo'       => 'nullable|boolean',
            'periodicidad' => 'nullable|string|in:anual,mensual,semestral,trimestral,semanal,ninguna',
        ]);

        $query = TipoInventario::query()->orderBy('nombre');

        // Filtro por estado activo/inactivo
        if ($request->has('activo')) {
            $esActivo = $request->boolean('activo');
            if ($esActivo) {
                $query->activos();
            } else {
                $query->inactivos();
            }
        }

        // Filtro por periodicidad
        if ($request->filled('periodicidad')) {
            $query->porPeriodicidad($request->string('periodicidad')->toString());
        }

        $tipos = $query->get()->map(function (TipoInventario $tipo) {
            return [
                'id'                      => $tipo->id,
                'nombre'                  => $tipo->nombre,
                'periodicidad'            => $tipo->periodicidad,
                'periodicidad_nombre'     => $tipo->periodicidad_nombre,
                'regla_validacion'        => $tipo->regla_validacion,
                'descripcion_restriccion' => $tipo->descripcion_restriccion,
                'activo'                  => $tipo->activo,
                'descripcion'             => $tipo->descripcion,
                'created_at'              => $tipo->created_at?->toISOString(),
                'updated_at'              => $tipo->updated_at?->toISOString(),
                'registros_count'         => $tipo->registros()->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $tipos,
        ]);
    }

    /**
     * POST /api/inventory/tipos-inventario
     *
     * Crea un nuevo tipo de inventario.
     *
     * Body:
     *   nombre: string (único)
     *   periodicidad: enum
     *   descripcion: string (opcional)
     *   activo: boolean (default true)
     */
    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre'           => 'required|string|max:100|unique:inv_tipos_inventario,nombre',
            'periodicidad'     => 'required|string|in:anual,mensual,semestral,trimestral,semanal,ninguna',
            'regla_validacion' => 'nullable|array',
            'descripcion'      => 'nullable|string|max:500',
            'activo'           => 'nullable|boolean',
        ], [
            'nombre.unique'        => 'Ya existe un tipo de inventario con este nombre.',
            'periodicidad.in'      => 'La periodicidad debe ser: anual, mensual, semestral, trimestral, semanal o ninguna.',
            'periodicidad.required' => 'La periodicidad es requerida.',
        ]);

        DB::beginTransaction();

        try {
            $tipo = TipoInventario::create([
                'nombre'           => $validado['nombre'],
                'periodicidad'     => $validado['periodicidad'],
                'regla_validacion' => $validado['regla_validacion'] ?? null,
                'descripcion'      => $validado['descripcion'] ?? null,
                'activo'           => $validado['activo'] ?? true,
            ]);

            $this->auditar('creado', $tipo, ['valores_nuevos' => $tipo->only(['nombre', 'periodicidad', 'activo'])]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tipo de inventario creado correctamente.',
                'data'    => [
                    'id'                      => $tipo->id,
                    'nombre'                  => $tipo->nombre,
                    'periodicidad'            => $tipo->periodicidad,
                    'periodicidad_nombre'     => $tipo->periodicidad_nombre,
                    'regla_validacion'        => $tipo->regla_validacion,
                    'descripcion_restriccion' => $tipo->descripcion_restriccion,
                    'activo'                  => $tipo->activo,
                    'descripcion'             => $tipo->descripcion,
                    'created_at'              => $tipo->created_at?->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el tipo de inventario: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/inventory/tipos-inventario/{id}
     *
     * Actualiza un tipo de inventario existente.
     *
     * Body:
     *   nombre: string (único, excepto el actual)
     *   periodicidad: enum
     *   descripcion: string (opcional)
     *   activo: boolean
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tipo = TipoInventario::find($id);

        if (!$tipo) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de inventario no encontrado.',
            ], 404);
        }

        $validado = $request->validate([
            'nombre'       => [
                'required',
                'string',
                'max:100',
                Rule::unique('inv_tipos_inventario', 'nombre')->ignore($tipo->id),
            ],
            'periodicidad'     => 'required|string|in:anual,mensual,semestral,trimestral,semanal,ninguna',
            'regla_validacion' => 'nullable|array',
            'descripcion'      => 'nullable|string|max:500',
            'activo'           => 'nullable|boolean',
        ], [
            'nombre.unique'        => 'Ya existe otro tipo de inventario con este nombre.',
            'periodicidad.in'      => 'La periodicidad debe ser: anual, mensual, semestral, trimestral, semanal o ninguna.',
            'periodicidad.required' => 'La periodicidad es requerida.',
        ]);

        DB::beginTransaction();

        try {
            $valoresAnteriores = $tipo->only(['nombre', 'periodicidad', 'regla_validacion', 'descripcion', 'activo']);

            $tipo->update([
                'nombre'           => $validado['nombre'],
                'periodicidad'     => $validado['periodicidad'],
                'regla_validacion' => $request->has('regla_validacion') ? $validado['regla_validacion'] : $tipo->regla_validacion,
                'descripcion'      => $validado['descripcion'] ?? $tipo->descripcion,
                'activo'           => $validado['activo'] ?? $tipo->activo,
            ]);

            $this->auditar('actualizado', $tipo, [
                'valores_anteriores' => $valoresAnteriores,
                'valores_nuevos'     => $tipo->only(['nombre', 'periodicidad', 'regla_validacion', 'descripcion', 'activo']),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tipo de inventario actualizado correctamente.',
                'data'    => [
                    'id'                      => $tipo->id,
                    'nombre'                  => $tipo->nombre,
                    'periodicidad'            => $tipo->periodicidad,
                    'periodicidad_nombre'     => $tipo->periodicidad_nombre,
                    'regla_validacion'        => $tipo->regla_validacion,
                    'descripcion_restriccion' => $tipo->descripcion_restriccion,
                    'activo'                  => $tipo->activo,
                    'descripcion'             => $tipo->descripcion,
                    'updated_at'              => $tipo->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el tipo de inventario: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/inventory/tipos-inventario/{id}
     *
     * Elimina (lógicamente) un tipo de inventario.
     * Solo se puede eliminar si NO tiene registros asociados.
     */
    public function destroy(int $id): JsonResponse
    {
        $tipo = TipoInventario::find($id);

        if (!$tipo) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de inventario no encontrado.',
            ], 404);
        }

        // Verificar si tiene registros asociados
        $conteo = $tipo->registros()->count();

        if ($conteo > 0) {
            return response()->json([
                'success' => false,
                'message' => "No se puede eliminar. Existen {$conteo} registro(s) asociados a este tipo de inventario.",
            ], 409);
        }

        DB::beginTransaction();

        try {
            $this->auditar('eliminado', $tipo);
            $tipo->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tipo de inventario eliminado correctamente.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el tipo de inventario: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /api/inventory/tipos-inventario/{id}/estado
     *
     * Activa o desactiva un tipo de inventario (toggle).
     * Opcionalmente puede recibir el estado específico en el body.
     *
     * Body (opcional):
     *   activo: boolean
     */
    public function toggleEstado(Request $request, int $id): JsonResponse
    {
        $tipo = TipoInventario::find($id);

        if (!$tipo) {
            return response()->json([
                'success' => false,
                'message' => 'Tipo de inventario no encontrado.',
            ], 404);
        }

        $request->validate([
            'activo' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            // Si se envía explícitamente el estado, usarlo
            if ($request->has('activo')) {
                $nuevoEstado = $request->boolean('activo');
                if ($nuevoEstado) {
                    $tipo->activar();
                } else {
                    $tipo->desactivar();
                }
            } else {
                // Toggle automático
                $tipo->toggleEstado();
            }

            $accion = $tipo->activo ? 'activado' : 'desactivado';

            $this->auditar("estado {$accion}", $tipo);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Tipo de inventario {$accion} correctamente.",
                'data'    => [
                    'id'     => $tipo->id,
                    'nombre' => $tipo->nombre,
                    'activo' => $tipo->activo,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Registro de auditoría (No funcional): deja traza de quién, cuándo y qué
     * cambió en la parametrización de tipos de inventario.
     *
     * @param array<string, mixed> $contexto
     */
    private function auditar(string $accion, TipoInventario $tipo, array $contexto = []): void
    {
        Log::info("TiposInventario: {$accion}", array_merge([
            'tipo_id'   => $tipo->id,
            'nombre'    => $tipo->nombre,
            'user_id'   => auth()->id(),
            'user'      => auth()->user()?->email,
            'timestamp' => now()->toIso8601String(),
        ], $contexto));
    }
}
