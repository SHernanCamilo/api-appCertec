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
     * Consulta al endpoint dedicado de urgencias (LH_INTEGRATIONS).
     *
     * Cambio 2026-08: antes usaba /api/data/dynamic contra ug.VW_HC_TableroUrgencias
     * (LH_MEDILASER_ANALYTICS, 3.311 vistas compitiendo). Ahora usa el endpoint
     * aislado /api/urgencias/tablero (LH_INTEGRATIONS, sin contención).
     */
    private function queryFromPython($user, ?string $sucursalFilter): ?array
    {
        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        // Timeouts holgados + un reintento: la vista de urgencias puede tardar
        // varios segundos bajo carga y un corte de 20s dejaba la pantalla vacia.
        $response = Http::timeout(40)
            ->connectTimeout(10)
            ->retry(2, 1500, throw: false)
            ->acceptJson()
            ->withHeaders(['X-API-Key' => env('GRAPHQL_API_KEY', '')])
            ->post($url . '/api/urgencias/tablero', [
                'token' => $token,
            ]);

        if ($response->failed()) {
            Log::error('TableroUrgencias: API Python error', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
            return null;
        }

        $allData = $response->json('data') ?? $response->json('items') ?? [];

        // Filtrar por sede del usuario si aplica.
        // Comparacion tolerante (mayusculas + trim): un " Tunja " en la vista o en
        // el nombre de la sucursal dejaba el tablero vacio con strcasecmp a secas.
        if ($sucursalFilter && !empty($allData)) {
            $objetivo = strtoupper(trim($sucursalFilter));

            $filtered = array_values(array_filter(
                $allData,
                fn ($row) => strtoupper(trim((string) ($row['Sede'] ?? ''))) === $objetivo
            ));
            return $filtered;
        }

        return $allData;
    }
}
