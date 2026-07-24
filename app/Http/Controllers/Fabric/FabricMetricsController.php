<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Proxy de métricas de Graph-Fabric Python.
 * Reenvía requests a http://127.0.0.1:8001/api/metrics/* con TOKEN_ADMIN.
 *
 * Solo accesible para admins autenticados (middleware auth:api + role check).
 */
class FabricMetricsController extends Controller
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $this->token = env('TOKEN_ADMIN', '');
    }

    /**
     * GET /api/fabric/metrics/service
     * Resumen completo: servicio + redis + queries + top views/users + slow queries
     */
    public function service(): JsonResponse
    {
        return $this->proxyGet('/api/metrics/service');
    }

    /**
     * GET /api/fabric/metrics/top-views?limit=20
     */
    public function topViews(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', '20'), 100);
        return $this->proxyGet("/api/metrics/queries/top-views", ['limit' => $limit]);
    }

    /**
     * GET /api/fabric/metrics/top-users?limit=20
     */
    public function topUsers(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', '20'), 100);
        return $this->proxyGet("/api/metrics/queries/top-users", ['limit' => $limit]);
    }

    /**
     * GET /api/fabric/metrics/slow?threshold_ms=5000&limit=20
     */
    public function slowQueries(Request $request): JsonResponse
    {
        $threshold = max(1000, (int) $request->query('threshold_ms', '5000'));
        $limit = min((int) $request->query('limit', '20'), 100);
        return $this->proxyGet("/api/metrics/queries/slow", [
            'threshold_ms' => $threshold,
            'limit' => $limit,
        ]);
    }

    /**
     * GET /api/fabric/metrics/history?limit=100
     */
    public function history(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', '100'), 500);
        return $this->proxyGet("/api/metrics/queries/history", ['limit' => $limit]);
    }

    /**
     * GET /api/fabric/metrics/fabric/active
     * Queries ejecutándose AHORA en Fabric (DMVs).
     */
    public function fabricActive(): JsonResponse
    {
        return $this->proxyGet("/api/metrics/fabric/active");
    }

    /**
     * GET /api/fabric/metrics/fabric/summary
     * Resumen de Fabric (sesiones + queries activas + Redis).
     */
    public function fabricSummary(): JsonResponse
    {
        return $this->proxyGet("/api/metrics/fabric/summary");
    }

    /**
     * Proxy a Graph-Fabric con contexto admin completo.
     * Graph-Fabric requiere token + user_context (groups, department) para validar admin.
     */
    private function proxyGet(string $path, array $params = []): JsonResponse
    {
        // Graph-Fabric valida admin vía get_user_permissions que necesita user_context.
        // Enviamos como query params el contexto mínimo para pasar la validación.
        $params['token'] = $this->token;
        $params['groups'] = 'GG-BD-ADMIN';
        $params['department'] = 'NAL-TIC NAL';
        $params['user_email'] = 'sistema@medilaser.com.co';
        $params['user_name'] = 'Sistema Laravel';

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->get($this->baseUrl . $path, $params);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'Graph-Fabric no respondió correctamente.',
                'status' => $response->status(),
            ], $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error conectando a Graph-Fabric: ' . $e->getMessage(),
            ], 502);
        }
    }
}
