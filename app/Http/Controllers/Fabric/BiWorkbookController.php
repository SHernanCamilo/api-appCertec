<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiWorkbookState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestiona el estado persistido del workbook Excel del visor BI.
 *
 * Endpoints:
 *   GET    /api/fabric/viewer/workbooks              -> Lista los workbooks del usuario
 *   GET    /api/fabric/viewer/workbook/{schema}/{view} -> Carga estado guardado
 *   POST   /api/fabric/viewer/workbook/save          -> Guarda estado actual
 *   DELETE /api/fabric/viewer/workbook/{id}           -> Elimina un workbook guardado
 */
class BiWorkbookController extends Controller
{
    /**
     * Lista todos los workbooks guardados del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $workbooks = BiWorkbookState::forUser($user->id)
            ->select('id', 'schema_name', 'view_name', 'name', 'is_default', 'updated_at')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $workbooks,
        ]);
    }

    /**
     * Carga el estado guardado de un workbook especifico.
     */
    public function show(Request $request, string $schema, string $view): JsonResponse
    {
        $user = auth()->user();

        $workbook = BiWorkbookState::forUser($user->id)
            ->forView($schema, $view)
            ->default()
            ->first();

        if (!$workbook) {
            return response()->json([
                'success' => true,
                'data'    => null,
                'message' => 'No hay estado guardado para esta vista.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $workbook,
        ]);
    }

    /**
     * Guarda el estado actual del workbook.
     *
     * Body esperado:
     * {
     *   "schema_name": "dc",
     *   "view_name": "VW_Censo_Eal",
     *   "name": "default",
     *   "state": {
     *     "sheets": [...],
     *     "activeSheetId": "...",
     *     "columnWidths": {...},
     *     "columnOrder": [...],
     *     "hiddenColumns": [...],
     *     "filters": [...],
     *     "pivotConfig": {...},
     *     "zoom": 100
     *   }
     * }
     */
    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20',
            'view_name'   => 'required|string|max:150',
            'name'        => 'nullable|string|max:100',
            'state'       => 'required|array',
        ]);

        $user = auth()->user();
        $name = $request->input('name', 'default');

        $workbook = BiWorkbookState::updateOrCreate(
            [
                'user_id'     => $user->id,
                'schema_name' => $request->input('schema_name'),
                'view_name'   => $request->input('view_name'),
                'name'        => $name,
            ],
            [
                'state'      => $request->input('state'),
                'is_default' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $workbook->only('id', 'schema_name', 'view_name', 'name', 'updated_at'),
            'message' => 'Workbook guardado correctamente.',
        ]);
    }

    /**
     * Elimina un workbook guardado.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();

        $deleted = BiWorkbookState::forUser($user->id)
            ->where('id', $id)
            ->delete();

        return response()->json([
            'success' => $deleted > 0,
            'message' => $deleted > 0 ? 'Workbook eliminado.' : 'No encontrado.',
        ]);
    }
}
