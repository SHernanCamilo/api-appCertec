<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\CtPlantilla;
use App\Models\Turnos\CtNovedadTipo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PlantillaController extends Controller
{
    // =========================================================================
    // PLANTILLAS DE TURNO
    // =========================================================================

    /**
     * GET /api/turnos/plantillas
     * Listar plantillas con filtros opcionales.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtPlantilla::with('empresa');

            if ($request->filled('id_empresa')) {
                $query->porEmpresa((int) $request->id_empresa);
            }

            if ($request->filled('estado')) {
                $query->where('estado', filter_var($request->estado, FILTER_VALIDATE_BOOLEAN));
            } else {
                $query->activas();
            }

            $plantillas = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data'    => $plantillas,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener plantillas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/plantillas
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'          => 'required|string|max:20|unique:humtal_ct_plantillas,codigo',
            'nombre'          => 'required|string|max:100',
            'hora_inicio'     => 'required|date_format:H:i',
            'hora_fin'        => 'required|date_format:H:i',
            'hora_inicio_2'   => 'nullable|date_format:H:i|required_with:hora_fin_2',
            'hora_fin_2'      => 'nullable|date_format:H:i|required_with:hora_inicio_2|after:hora_inicio_2',
            'duracion_horas'  => 'required|numeric|min:0.5|max:24',
            'es_nocturno'     => 'boolean',
            'color_hex'       => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'id_empresa'      => 'nullable|integer|exists:ent_empresas,id',
            'estado'          => 'boolean',
        ]);

        try {
            // Validar que el primer rango esté antes del segundo si hay jornada partida
            if ($request->filled('hora_inicio_2') && $request->filled('hora_fin_2')) {
                if ($request->hora_fin > $request->hora_inicio_2) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El primer rango debe terminar antes del inicio del segundo rango.',
                    ], 422);
                }
            }

            $plantilla = CtPlantilla::create($request->all());

            // Recalcular duracion_horas automáticamente si no se envió o está incorrecta
            $duracionCalculada = $plantilla->calcularDuracionTotal();
            if (abs($plantilla->duracion_horas - $duracionCalculada) > 0.01) {
                $plantilla->update(['duracion_horas' => $duracionCalculada]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Plantilla creada exitosamente.',
                'data'    => $plantilla->fresh(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear plantilla: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/plantillas/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $plantilla = CtPlantilla::with('empresa')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => array_merge($plantilla->toArray(), [
                    'duracion_formateada' => $plantilla->getDuracionFormateada(),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Plantilla no encontrada.',
            ], 404);
        }
    }

    /**
     * PUT /api/turnos/plantillas/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo'         => "string|max:20|unique:humtal_ct_plantillas,codigo,{$id}",
            'nombre'         => 'string|max:100',
            'hora_inicio'    => 'date_format:H:i',
            'hora_fin'       => 'date_format:H:i',
            'hora_inicio_2'  => 'nullable|date_format:H:i',
            'hora_fin_2'     => 'nullable|date_format:H:i',
            'duracion_horas' => 'numeric|min:0.5|max:24',
            'es_nocturno'    => 'boolean',
            'color_hex'      => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'id_empresa'     => 'nullable|integer|exists:ent_empresas,id',
            'estado'         => 'boolean',
        ]);

        try {
            $plantilla = CtPlantilla::findOrFail($id);
            $plantilla->update($request->all());

            // Recalcular duracion_horas automáticamente
            $duracionCalculada = $plantilla->calcularDuracionTotal();
            if (abs($plantilla->duracion_horas - $duracionCalculada) > 0.01) {
                $plantilla->update(['duracion_horas' => $duracionCalculada]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Plantilla actualizada.',
                'data'    => $plantilla->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar plantilla: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/turnos/plantillas/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $plantilla = CtPlantilla::findOrFail($id);

            // Verificar que no tenga asignaciones activas
            if ($plantilla->asignaciones()->exists()) {
                // Soft delete: desactivar en lugar de eliminar
                $plantilla->update(['estado' => false]);
                return response()->json([
                    'success' => true,
                    'message' => 'Plantilla desactivada (tiene asignaciones asociadas).',
                ]);
            }

            $plantilla->delete();

            return response()->json([
                'success' => true,
                'message' => 'Plantilla eliminada.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar plantilla: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // TIPOS DE NOVEDAD
    // =========================================================================

    /**
     * GET /api/turnos/novedad-tipos
     */
    public function indexNovedadTipos(Request $request): JsonResponse
    {
        try {
            $query = CtNovedadTipo::query();

            if ($request->filled('estado')) {
                $query->where('estado', filter_var($request->estado, FILTER_VALIDATE_BOOLEAN));
            } else {
                $query->activos();
            }

            $tipos = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data'    => $tipos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tipos de novedad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/novedad-tipos
     */
    public function storeNovedadTipo(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'              => 'required|string|max:30|unique:humtal_ct_novedad_tipo,codigo',
            'nombre'              => 'required|string|max:100',
            'descripcion'         => 'nullable|string',
            'afecta_turno'        => 'boolean',
            'requiere_reemplazo'  => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'color_hex'           => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'estado'              => 'boolean',
        ]);

        try {
            $tipo = CtNovedadTipo::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Tipo de novedad creado.',
                'data'    => $tipo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear tipo de novedad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/turnos/novedad-tipos/{id}
     */
    public function updateNovedadTipo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo'              => "string|max:30|unique:humtal_ct_novedad_tipo,codigo,{$id}",
            'nombre'              => 'string|max:100',
            'descripcion'         => 'nullable|string',
            'afecta_turno'        => 'boolean',
            'requiere_reemplazo'  => 'boolean',
            'requiere_aprobacion' => 'boolean',
            'color_hex'           => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'estado'              => 'boolean',
        ]);

        try {
            $tipo = CtNovedadTipo::findOrFail($id);
            $tipo->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Tipo de novedad actualizado.',
                'data'    => $tipo->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar tipo de novedad: ' . $e->getMessage(),
            ], 500);
        }
    }
}
