<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Gateway hacia la API Python Graph-Fabric.
 *
 * Flujo de seguridad:
 *   1. El JWT del frontend se verifica aquí en Laravel
 *   2. Se leen los grupos GG-BD-* del usuario desde users_grups (sincronizados en login)
 *   3. Se valida que el esquema solicitado esté en los grupos del usuario
 *   4. Se reenvía la solicitud a la API Python con TOKEN_ADMIN (token de servicio)
 *   5. Se devuelve la respuesta al frontend
 *
 * Endpoints:
 *   POST /api/fabric/viewer/views    → Vistas permitidas para el usuario
 *   POST /api/fabric/viewer/columns  → Columnas de una vista
 *   POST /api/fabric/viewer/data     → Datos paginados de una vista
 *   POST /api/fabric/viewer/export   → Export a Excel (descarga)
 */
class FabricViewerController extends Controller
{
    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {}

    // =========================================================================
    // VISTAS PERMITIDAS
    // =========================================================================

    /**
     * Retorna las vistas de Fabric que el usuario autenticado puede ver.
     * Usa los grupos GG-BD-* y el departamento almacenados en users_grups.
     *
     * POST /api/fabric/viewer/views
     *
     * Body: (vacío — toda la info viene del JWT del usuario autenticado)
     *
     * Response:
     * {
     *   "success": true,
     *   "grupos":   ["GG-BD-IN", "GG-BD-CO"],
     *   "esquemas": ["in", "co"],
     *   "departamento": "MA-TIC",
     *   "data": { ...respuesta de la API Py /api/catalog/views... }
     * }
     */
    public function views(Request $request): JsonResponse
    {
        $user = auth()->user();

        $result = $this->gateway->getViewsForUser($user);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error obteniendo vistas.',
                'data'    => [],
            ], 403);
        }

        return response()->json($result);
    }

    // =========================================================================
    // COLUMNAS DE UNA VISTA
    // =========================================================================

    /**
     * Retorna las columnas de una vista específica.
     * Valida que el usuario tenga acceso al esquema solicitado.
     *
     * POST /api/fabric/viewer/columns
     *
     * Body:
     * {
     *   "schema_name": "in",
     *   "view_name":   "VW_Inventory_Almacenes"
     * }
     */
    public function columns(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view_name'   => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);
        $view   = $request->view_name;

        $result = $this->gateway->getViewColumns($user, $schema, $view);

        if (!$result['success']) {
            $code = $result['code'] ?? 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $code);
        }

        return response()->json($result);
    }

    // =========================================================================
    // DATOS PAGINADOS
    // =========================================================================

    /**
     * Consulta datos paginados de una vista de Fabric.
     * Valida acceso al esquema, luego hace proxy a /api/data/dynamic.
     *
     * POST /api/fabric/viewer/data
     *
     * Body:
     * {
     *   "schema_name": "in",
     *   "view":        "VW_Inventory_Almacenes",
     *   "columns":     ["codigo", "producto", "cantidad"],  // [] = todas
     *   "filters":     {"estado": "ACTIVO", "producto": "%AMOX%"},
     *   "limit":       50,
     *   "offset":      0,
     *   "sort_col":    "codigo",
     *   "sort_dir":    "asc"
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "data": [...],
     *   "meta": {
     *     "total": 28766,
     *     "limit": 50,
     *     "offset": 0,
     *     "has_next": true,
     *     "elapsed_ms": 657.2
     *   }
     * }
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'columns'     => 'nullable|array',
            'columns.*'   => 'string|max:100',
            'filters'     => 'nullable|array',
            'limit'       => 'nullable|integer|min:1|max:5000',
            'offset'      => 'nullable|integer|min:0',
            'sort_col'    => 'nullable|string|max:100',
            'sort_dir'    => 'nullable|in:asc,desc',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);

        $result = $this->gateway->queryViewData(
            $user,
            $schema,
            $request->view,
            [
                'columns'  => $request->input('columns', []),
                'filters'  => $request->input('filters', []),
                'limit'    => $request->input('limit', 50),
                'offset'   => $request->input('offset', 0),
                'sort_col' => $request->input('sort_col', ''),
                'sort_dir' => $request->input('sort_dir', 'asc'),
            ]
        );

        if (!$result['success']) {
            $code = $result['code'] ?? 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $code);
        }

        return response()->json($result);
    }

    // =========================================================================
    // EXPORT A EXCEL
    // =========================================================================

    /**
     * Exporta una vista a Excel y la devuelve como descarga.
     * Valida acceso al esquema antes de exportar.
     *
     * POST /api/fabric/viewer/export
     *
     * Body:
     * {
     *   "schema_name": "in",
     *   "view":        "VW_Inventory_Almacenes",
     *   "columns":     [],          // [] = todas las columnas
     *   "filters":     {"estado": "ACTIVO"},
     *   "sort_col":    "codigo",
     *   "sort_dir":    "asc",
     *   "max_rows":    50000
     * }
     *
     * Response: archivo .xlsx para descarga directa
     */
    public function export(Request $request): mixed
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'columns'     => 'nullable|array',
            'columns.*'   => 'string|max:100',
            'filters'     => 'nullable|array',
            'sort_col'    => 'nullable|string|max:100',
            'sort_dir'    => 'nullable|in:asc,desc',
            'max_rows'    => 'nullable|integer|min:1|max:1048576',
        ]);

        $user   = auth()->user();
        $schema = strtolower($request->schema_name);

        $result = $this->gateway->exportViewExcel(
            $user,
            $schema,
            $request->view,
            [
                'columns'  => $request->input('columns', []),
                'filters'  => $request->input('filters', []),
                'sort_col' => $request->input('sort_col', ''),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'max_rows' => $request->input('max_rows', 100000),
            ]
        );

        if (!$result['success']) {
            $code = $result['code'] ?? 400;
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $code);
        }

        return response($result['content'], 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    // =========================================================================
    // INFO DEL USUARIO (debug / contexto)
    // =========================================================================

    /**
     * Retorna el contexto del usuario autenticado:
     * grupos GG-BD-*, esquemas permitidos y departamento.
     * Útil para el frontend al inicializar el visor.
     *
     * GET /api/fabric/viewer/context
     */
    public function context(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'success'      => true,
            'user'         => $user->email,
            'grupos'       => $this->gateway->getGruposBd($user),
            'esquemas'     => $this->gateway->getEsquemasPermitidos($user),
            'departamento' => $this->gateway->getDepartamento($user),
        ]);
    }
}
