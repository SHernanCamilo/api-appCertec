<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tableros;

use App\Http\Controllers\Controller;
use App\Models\TableroToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoint PÚBLICO para tableros informativos (sin login).
 *
 * Seguridad:
 *   - Token secreto por sede (parametrizable, revocable)
 *   - Solo lectura de una vista fija (no acepta queries arbitrarias)
 *   - Máximo N conexiones SSE simultáneas por token
 *   - Registra IP y uso para auditoría
 *
 * No requiere auth:api. No toca el parquet. Siempre va a Fabric directo
 * para garantizar datos en tiempo real.
 */
final class TableroPublicController extends Controller
{
    /**
     * GET /api/public/tableros/urgencias/stream?token=xxx
     *
     * SSE: mantiene la conexión abierta y envía datos cada 30 segundos.
     * La TV nunca hace polling — recibe push del servidor.
     */
    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        $tokenStr = $request->query('token', '');

        $tableroToken = TableroToken::findByToken((string) $tokenStr);

        if ($tableroToken === null) {
            return response()->json([
                'error'   => 'invalid_token',
                'message' => 'Token inválido, expirado o revocado.',
            ], 401);
        }

        // Validar máximo de conexiones simultáneas por token
        $connKey = "tablero_sse:{$tableroToken->id}";
        $active  = (int) Cache::get($connKey, 0);

        if ($active >= $tableroToken->max_connections) {
            return response()->json([
                'error'   => 'max_connections',
                'message' => 'Máximo de pantallas alcanzado para este token.',
            ], 429);
        }

        // Registrar uso
        $tableroToken->recordUse($request->ip() ?? '0.0.0.0');

        // Incrementar conexiones activas
        Cache::increment($connKey);

        $response = new StreamedResponse(function () use ($tableroToken, $connKey) {
            // Desactivar output buffering de PHP
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $intervalSeconds = (int) env('TABLERO_SSE_INTERVAL', 30);
            $maxDuration     = (int) env('TABLERO_SSE_MAX_DURATION', 3600); // 1 hora máximo
            $startTime       = time();

            // Evento inicial inmediato (la TV no espera 30s para mostrar algo)
            $data = $this->fetchData($tableroToken);
            $this->sendSseEvent('data', $data);

            while (true) {
                // Límite de duración: reconecta limpio después de 1h
                if ((time() - $startTime) >= $maxDuration) {
                    $this->sendSseEvent('reconnect', ['reason' => 'max_duration']);
                    break;
                }

                // Verificar que la conexión sigue viva
                if (connection_aborted()) {
                    break;
                }

                sleep($intervalSeconds);

                if (connection_aborted()) {
                    break;
                }

                // Consultar Fabric (siempre datos reales, nunca parquet)
                $data = $this->fetchData($tableroToken);
                $this->sendSseEvent('data', $data);
            }

            // Decrementar conexiones al cerrar
            Cache::decrement($connKey);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Nginx no bufferee
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    /**
     * GET /api/public/tableros/urgencias/data?token=xxx
     *
     * Alternativa sin SSE: devuelve los datos una vez (fallback para TVs
     * que no soportan EventSource). El frontend puede hacer polling manual.
     */
    public function data(Request $request): JsonResponse
    {
        $tokenStr = $request->query('token', '');

        $tableroToken = TableroToken::findByToken((string) $tokenStr);

        if ($tableroToken === null) {
            return response()->json([
                'error'   => 'invalid_token',
                'message' => 'Token inválido, expirado o revocado.',
            ], 401);
        }

        $tableroToken->recordUse($request->ip() ?? '0.0.0.0');

        $data = $this->fetchData($tableroToken);

        return response()->json($data, 200, [
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    // =========================================================================
    // DATA
    // =========================================================================

    /**
     * Consulta DIRECTA a Fabric (nunca parquet) para datos en tiempo real.
     * Cachea 15 segundos para no golpear si dos TVs de la misma sede piden a la vez.
     *
     * @return array{success: bool, data: array, sede: ?string, timestamp: string}
     */
    private function fetchData(TableroToken $tableroToken): array
    {
        $cacheKey = "tablero_public:{$tableroToken->id}";
        $cacheTtl = (int) env('TABLERO_PUBLIC_CACHE_TTL', 15);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        $filters = new \stdClass();
        if ($tableroToken->sede_filter) {
            $filters = ['Sede' => $tableroToken->sede_filter];
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(5)
                ->acceptJson()
                ->post($url . '/api/data/dynamic', [
                    'token'       => $token,
                    'groups'      => ['GG-BD-' . strtoupper($tableroToken->schema_name), 'GG-BD-ADMIN'],
                    'department'  => 'NAL-TIC NAL',
                    'user_email'  => 'tablero@medilaser.com.co',
                    'user_name'   => 'Tablero Público',
                    'schema_name' => $tableroToken->schema_name,
                    'view'        => $tableroToken->view_name,
                    'columns'     => [],
                    'filters'     => $filters,
                    'limit'       => 50,
                    'offset'      => 0,
                    'sort_col'    => 'Sede',
                    'sort_dir'    => 'asc',
                    'skip_count'  => true,
                ]);

            if ($response->failed()) {
                Log::warning('TableroPublic: Fabric no respondió', [
                    'token_id' => $tableroToken->id,
                    'status'   => $response->status(),
                ]);

                return [
                    'success'   => false,
                    'data'      => [],
                    'sede'      => $tableroToken->sede_filter,
                    'timestamp' => now()->toIso8601String(),
                    'error'     => 'service_unavailable',
                ];
            }

            $items = $response->json('items') ?? [];

            $result = [
                'success'   => true,
                'data'      => $items,
                'sede'      => $tableroToken->sede_filter,
                'timestamp' => now()->toIso8601String(),
            ];

            Cache::put($cacheKey, $result, $cacheTtl);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('TableroPublic: excepción', [
                'token_id' => $tableroToken->id,
                'error'    => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'data'      => [],
                'sede'      => $tableroToken->sede_filter,
                'timestamp' => now()->toIso8601String(),
                'error'     => 'connection_error',
            ];
        }
    }

    /**
     * Envía un evento SSE al cliente.
     */
    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
