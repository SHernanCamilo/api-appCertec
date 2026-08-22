<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestiona los workbooks (Excels guardados) del usuario.
 *
 * Es la interfaz para "Mis Excels": crear, listar, abrir, actualizar y eliminar
 * workbooks multi-vista. Cada workbook guarda QUE vistas incluye y el estado UI
 * completo (hojas, filtros, formulas, zoom, columnas ocultas...).
 *
 * Endpoints:
 *   GET    /api/fabric/viewer/my-workbooks              -> Lista del usuario
 *   GET    /api/fabric/viewer/my-workbook/{id}          -> Carga un workbook completo
 *   POST   /api/fabric/viewer/my-workbook               -> Crea uno nuevo
 *   PUT    /api/fabric/viewer/my-workbook/{id}          -> Actualiza (nombre, estado, favorito)
 *   PUT    /api/fabric/viewer/my-workbook/{id}/state    -> Auto-save (solo estado)
 *   DELETE /api/fabric/viewer/my-workbook/{id}          -> Elimina
 */
class BiWorkbookManagerController extends Controller
{
    /**
     * Lista todos los workbooks del usuario con resumen.
     *
     * No devuelve el `state` (puede pesar MBs con muchas formulas).
     * El frontend los muestra como tarjetas tipo "Mis Excels".
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $workbooks = BiWorkbook::forUser($user->id)
            ->select('id', 'name', 'description', 'views', 'is_favorite', 'last_opened_at', 'updated_at', 'created_at')
            ->orderByDesc('last_opened_at')
            ->get()
            ->map(function (BiWorkbook $wb) {
                return [
                    'id'             => $wb->id,
                    'name'           => $wb->name,
                    'description'    => $wb->description,
                    'views'          => $wb->views,
                    'viewCount'      => $wb->viewCount(),
                    'viewNames'      => $wb->viewNames(),
                    'is_favorite'    => $wb->is_favorite,
                    'last_opened_at' => $wb->last_opened_at?->toISOString(),
                    'updated_at'     => $wb->updated_at?->toISOString(),
                    'created_at'     => $wb->created_at?->toISOString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $workbooks,
            'total'   => $workbooks->count(),
        ]);
    }

    /**
     * Carga un workbook completo (con su estado).
     *
     * Tambien actualiza `last_opened_at` para ordenar por "recientes".
     */
    public function show(int $id): JsonResponse
    {
        $user = auth()->user();

        $workbook = BiWorkbook::forUser($user->id)->find($id);

        if (!$workbook) {
            return response()->json([
                'success' => false,
                'message' => 'Workbook no encontrado.',
            ], 404);
        }

        // Marcar como abierto
        $workbook->update(['last_opened_at' => now()]);

        return response()->json([
            'success' => true,
            'data'    => $workbook,
        ]);
    }

    /**
     * Crea un nuevo workbook.
     *
     * Body esperado:
     * {
     *   "name": "Analisis Censo + Facturas",
     *   "description": "Cruce de censo con facturacion",
     *   "views": [
     *     { "schema": "dc", "viewName": "VW_Censo_Eal", "label": "Censo Estancia" },
     *     { "schema": "dc", "viewName": "VW_Facturacion", "label": "Facturacion" }
     *   ],
     *   "state": { ... }   // Opcional: estado UI inicial
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'views'       => 'required|array|min:1|max:5',
            'views.*.schema'   => 'required|string|max:20',
            'views.*.viewName' => 'required|string|max:150',
            'views.*.label'    => 'required|string|max:150',
            'state'       => 'nullable|array',
        ]);

        $user = auth()->user();

        $workbook = BiWorkbook::create([
            'user_id'        => $user->id,
            'name'           => $request->input('name'),
            'description'    => $request->input('description'),
            'views'          => $request->input('views'),
            'state'          => $request->input('state'),
            'last_opened_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $workbook,
            'message' => 'Workbook creado correctamente.',
        ], 201);
    }

    /**
     * Actualiza un workbook (nombre, descripcion, vistas, estado, favorito).
     *
     * El frontend puede enviar solo los campos que cambiaron.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name'        => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:500',
            'views'       => 'sometimes|array|min:1|max:5',
            'state'       => 'nullable|array',
            'is_favorite' => 'sometimes|boolean',
        ]);

        $user = auth()->user();
        $workbook = BiWorkbook::forUser($user->id)->find($id);

        if (!$workbook) {
            return response()->json([
                'success' => false,
                'message' => 'Workbook no encontrado.',
            ], 404);
        }

        $workbook->update($request->only(['name', 'description', 'views', 'state', 'is_favorite']));

        return response()->json([
            'success' => true,
            'data'    => $workbook->fresh(),
            'message' => 'Workbook actualizado.',
        ]);
    }

    /**
     * Auto-save: actualiza SOLO el estado UI del workbook.
     *
     * Es el endpoint que se llama cada 3 segundos (debounced) mientras
     * el usuario trabaja. No requiere enviar nombre ni vistas, solo el blob
     * de estado.
     */
    public function saveState(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'state' => 'required|array',
        ]);

        $user = auth()->user();
        $workbook = BiWorkbook::forUser($user->id)->find($id);

        if (!$workbook) {
            return response()->json([
                'success' => false,
                'message' => 'Workbook no encontrado.',
            ], 404);
        }

        $workbook->update([
            'state'          => $request->input('state'),
            'last_opened_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado guardado.',
        ]);
    }

    /**
     * Elimina un workbook.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();

        $deleted = BiWorkbook::forUser($user->id)
            ->where('id', $id)
            ->delete();

        return response()->json([
            'success' => $deleted > 0,
            'message' => $deleted > 0 ? 'Workbook eliminado.' : 'No encontrado.',
        ]);
    }
}
