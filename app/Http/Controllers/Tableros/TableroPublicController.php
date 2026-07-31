<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tableros;

use App\Http\Controllers\Controller;
use App\Models\TableroDevice;
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
 * Dos formas de autenticarse:
 *   1. device_secret (permanente, emparejado con código de 6 dígitos)
 *   2. token (legacy, por URL — para compatibilidad)
 *
 * Seguridad:
 *   - Código de 6 dígitos válido solo 5 min, un solo uso
 *   - device_secret permanente vinculado a una sede
 *   - Solo lectura de una vista fija
 *   - Registra IP para auditoría (pero NO limita por IP porque comparten red)
 *   - Máx N conexiones SSE simultáneas por dispositivo
 */
final class TableroPublicController extends Controller
{
    /**
     * POST /api/public/tableros/pair — Emparejar TV con código de 6 dígitos.
     *
     * La TV envía el código → recibe device_secret permanente.
     * El código se invalida inmediatamente (un solo uso).
     */
    public function pair(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $code = $request->input('code');

        // Rate limit: máx 5 intentos por IP en 10 min (anti fuerza bruta)
        $rateLimitKey = 'tablero_pair_attempts:' . $request->ip();
        $attempts = (int) Cache::get($rateLimitKey, 0);

        if ($attempts >= 5) {
            return response()->json([
                'success' => false,
                'error'   => 'too_many_attempts',
                'message' => 'Demasiados intentos. Espere 10 minutos.',
            ], 429);
        }

        Cache::put($rateLimitKey, $attempts + 1, 600); // 10 min

        $device = TableroDevice::findByPairingCode($code);

        if ($device === null) {
            return response()->json([
                'success' => false,
                'error'   => 'invalid_code',
                'message' => 'Código inválido o expirado. Solicite uno nuevo al administrador.',
            ], 401);
        }

        // Emparejar: consume el código y genera el device_secret
        $secret = $device->pair(
            $request->ip() ?? '0.0.0.0',
            $request->userAgent() ?? 'unknown'
        );

        // Resetear rate limit para esta IP
        Cache::forget($rateLimitKey);

        return response()->json([
            'success'       => true,
            'device_secret' => $secret,
            'name'          => $device->name,
            'sede'          => $device->sede_filter,
            'message'       => 'Tablero activado correctamente. La pantalla se actualizará automáticamente.',
        ]);
    }

    /**
     * GET /api/public/tableros/urgencias/stream?d=DEVICE_SECRET
     *
     * SSE: mantiene la conexión abierta y envía datos cada 30 segundos.
     * La TV nunca hace polling — recibe push del servidor.
     */
    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device === null) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Dispositivo no autorizado. Vuelva a emparejar.',
            ], 401);
        }

        // Validar máximo de conexiones simultáneas por dispositivo
        $connKey = "tablero_sse_dev:{$device->id}";
        $active  = (int) Cache::get($connKey, 0);

        if ($active >= $device->max_connections) {
            return response()->json([
                'error'   => 'max_connections',
                'message' => 'Máximo de pantallas alcanzado para este dispositivo.',
            ], 429);
        }

        // Registrar actividad
        $device->recordActivity($request->ip() ?? '0.0.0.0');

        // Incrementar conexiones activas
        Cache::increment($connKey);

        $response = new StreamedResponse(function () use ($device, $connKey) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            $intervalSeconds = (int) env('TABLERO_SSE_INTERVAL', 30);
            $maxDuration     = (int) env('TABLERO_SSE_MAX_DURATION', 3600);
            $startTime       = time();

            // Evento inicial inmediato
            $data = $this->fetchData($device);
            $this->sendSseEvent('data', $data);

            while (true) {
                if ((time() - $startTime) >= $maxDuration) {
                    $this->sendSseEvent('reconnect', ['reason' => 'max_duration']);
                    break;
                }

                if (connection_aborted()) {
                    break;
                }

                sleep($intervalSeconds);

                if (connection_aborted()) {
                    break;
                }

                $data = $this->fetchData($device);
                $this->sendSseEvent('data', $data);
            }

            Cache::decrement($connKey);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    /**
     * GET /api/public/tableros/urgencias/data?d=DEVICE_SECRET
     *
     * Alternativa sin SSE (fallback). Devuelve datos una vez.
     */
    public function data(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        if ($device === null) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Dispositivo no autorizado.',
            ], 401);
        }

        $device->recordActivity($request->ip() ?? '0.0.0.0');

        return response()->json($this->fetchData($device), 200, [
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    // =========================================================================
    // RESOLUCIÓN DEL DISPOSITIVO
    // =========================================================================

    /**
     * Resuelve el dispositivo desde la request.
     * Soporta: ?d=DEVICE_SECRET (nuevo) o ?token=TOKEN (legacy)
     */
    private function resolveDevice(Request $request): ?TableroDevice
    {
        // Nuevo: device_secret
        $secret = $request->query('d', '');
        if ($secret && strlen((string) $secret) >= 10) {
            return TableroDevice::findBySecret((string) $secret);
        }

        // Legacy: token de TableroToken
        $token = $request->query('token', '');
        if ($token && strlen((string) $token) >= 10) {
            $tableroToken = TableroToken::findByToken((string) $token);
            if ($tableroToken) {
                $tableroToken->recordUse($request->ip() ?? '0.0.0.0');
                // Crear un device virtual para reutilizar el flujo
                $virtual = new TableroDevice();
                $virtual->id = 0;
                $virtual->schema_name = $tableroToken->schema_name;
                $virtual->view_name = $tableroToken->view_name;
                $virtual->sede_filter = $tableroToken->sede_filter;
                $virtual->max_connections = $tableroToken->max_connections;
                return $virtual;
            }
        }

        return null;
    }

    // =========================================================================
    // DATA
    // =========================================================================

    /**
     * Consulta DIRECTA a Fabric para datos en tiempo real.
     * SIN CACHE: esta vista es liviana (6 filas) y los tiempos de espera
     * cambian minuto a minuto. Cachear causa que el tablero muestre datos
     * viejos mientras el temporizador real sigue subiendo en Fabric.
     */
    private function fetchData(TableroDevice $device): array
    {
        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        $filters = new \stdClass();
        if ($device->sede_filter) {
            $filters = ['Sede' => $device->sede_filter];
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(5)
                ->acceptJson()
                ->post($url . '/api/data/dynamic', [
                    'token'       => $token,
                    'groups'      => ['GG-BD-' . strtoupper($device->schema_name), 'GG-BD-ADMIN'],
                    'department'  => 'NAL-TIC NAL',
                    'user_email'  => 'tablero@medilaser.com.co',
                    'user_name'   => 'Tablero Público',
                    'schema_name' => $device->schema_name,
                    'view'        => $device->view_name,
                    'columns'     => [],
                    'filters'     => $filters,
                    'limit'       => 50,
                    'offset'      => 0,
                    'sort_col'    => 'Sede',
                    'sort_dir'    => 'asc',
                    'skip_count'  => true,
                ]);

            if ($response->failed()) {
                return [
                    'success'   => false,
                    'data'      => [],
                    'sede'      => $device->sede_filter,
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            return [
                'success'   => true,
                'data'      => $response->json('items') ?? [],
                'sede'      => $device->sede_filter,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('TableroPublic: error', ['device' => $device->id, 'error' => $e->getMessage()]);

            return [
                'success'   => false,
                'data'      => [],
                'sede'      => $device->sede_filter,
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

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
