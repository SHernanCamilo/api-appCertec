<?php

namespace App\Http\Controllers\Tableros;

use App\Http\Controllers\Controller;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tablero de Urgencias — Vista [UG].[VW_HC_TableroUrgencias]
 *
 * Usa la misma metodología que los formularios:
 * → Llama directamente a la API Python (Graph-Fabric) con el TOKEN_ADMIN
 * → El usuario tiene la vista delegada directamente
 *
 * Requiere: auth:api + rol "Tablero"
 * Filtra automáticamente por la sucursal del usuario autenticado.
 */
class TableroUrgenciasController extends Controller
{
    private const SCHEMA = 'ug';
    private const VIEW   = 'VW_HC_TableroUrgencias';
    private const CACHE_TTL = 30;

    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {}

    /**
     * GET /api/tableros/urgencias
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        // Validar rol "Tablero"
        $roles = $user->rolesCustom->pluck('nombre')->map(fn($r) => strtolower($r))->toArray();
        if (!in_array('tablero', $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para acceder al tablero de urgencias.',
            ], 403);
        }

        // Obtener sucursal del usuario para filtrar
        $sucursal = $user->sucursal;
        $sucursalNombre = $sucursal?->nombre;

        try {
            $cacheKey = 'tablero_urgencias_' . $user->id . '_' . ($sucursalNombre ?? 'all');

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $sucursalNombre) {
                return $this->queryFromPython($user, $sucursalNombre);
            });

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo conectar con el servicio de datos.',
                ], 503);
            }

            return response()->json([
                'success'  => true,
                'data'     => $data,
                'sucursal' => $sucursalNombre,
            ]);
        } catch (\Exception $e) {
            Log::error('TableroUrgencias: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error consultando tablero de urgencias.',
            ], 503);
        }
    }

    /**
     * Consulta directamente a la API Python (misma forma que los formularios).
     * El usuario tiene la vista delegada → no requiere grupo GG-BD-UG.
     */
    private function queryFromPython($user, ?string $sucursalFilter): ?array
    {
        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        $filters = [];
        if ($sucursalFilter) {
            $filters['Sede'] = $sucursalFilter;
        }

        $payload = [
            'token'       => $token,
            'groups'      => $this->gateway->getGruposBd($user) ?: ['GG-BD-UG'],
            'department'  => $this->gateway->resolveDepartmentForGrantView($user) ?? 'NAL',
            'user_email'  => $user->email,
            'user_name'   => $user->name ?? $user->email,
            'schema_name' => self::SCHEMA,
            'view'        => self::VIEW,
            'columns'     => [],
            'filters'     => $filters,
            'limit'       => 100,
            'offset'      => 0,
            'sort_col'    => 'Sede',
            'sort_dir'    => 'asc',
            'skip_count'  => true,
        ];

        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->acceptJson()
            ->withHeaders(['X-API-Key' => env('GRAPHQL_API_KEY', '')])
            ->post($url . '/api/data/dynamic', $payload);

        if ($response->failed()) {
            Log::error('TableroUrgencias: API Python error', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $json = $response->json();
        return $json['items'] ?? [];
    }
}
