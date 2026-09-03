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
        $deviceId = (string) $request->input('device_id', '');

        $secret = $device->pair(
            $request->ip() ?? '0.0.0.0',
            $request->userAgent() ?? 'unknown',
            $deviceId
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
     * POST /api/public/tableros/urgencias/reconnect — Reconectar TV por deviceId.
     *
     * Si la TV perdió el localStorage (limpió cache, se reinició) pero tiene su
     * deviceId guardado en IndexedDB o en una cookie, puede reconectarse sin código.
     *
     * El deviceId es un UUID generado la primera vez que la TV carga la app y
     * guardado en 3 capas (localStorage + IndexedDB + cookie). Es único por TV
     * incluso si comparten IP y modelo — porque es un UUID aleatorio, no un
     * hash de hardware.
     */
    public function reconnect(Request $request): JsonResponse
    {
        $deviceId    = (string) $request->input('device_id', '');
        $fingerprint = (string) $request->input('fingerprint', '');
        $ip          = $request->ip() ?? '0.0.0.0';

        $device = null;
        $via    = '';

        // ── Estrategia 1: por device_id (UUID en localStorage/IndexedDB/cookie) ──
        if (strlen($deviceId) >= 10) {
            $device = TableroDevice::where('device_id', $deviceId)
                ->where('paired', true)
                ->where('active', true)
                ->first();
            if ($device) $via = 'device_id';
        }

        // ── Estrategia 2: por fingerprint + IP (si perdió el device_id) ──
        // Robusto para TVs que limpiaron TODO el storage pero siguen en la
        // misma red/equipo. El fingerprint viene del navegador (UA + pantalla).
        if ($device === null && strlen($fingerprint) >= 3) {
            $device = TableroDevice::findByFingerprint($fingerprint, $ip);
            if ($device) $via = 'fingerprint+ip';
        }

        // ── Estrategia 3: última TV activa en esta IP (último recurso) ──
        // Si solo hay UNA TV emparejada en esta IP, la reconectamos. Si hay
        // varias (misma sala comparte IP), NO adivinar: pedir código.
        if ($device === null) {
            $candidates = TableroDevice::where('last_ip', $ip)
                ->where('paired', true)
                ->where('active', true)
                ->get();
            if ($candidates->count() === 1) {
                $device = $candidates->first();
                $via    = 'unica_ip';
            }
        }

        if ($device === null) {
            Log::info('TableroPublic: reconexión fallida', [
                'ip'          => $ip,
                'device_uuid' => substr($deviceId, 0, 12),
                'fingerprint' => substr($fingerprint, 0, 12),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Dispositivo no reconocido. Ingrese el código de activación.',
            ], 404);
        }

        // Guardar/actualizar el device_id y fingerprint para futuras reconexiones
        $updates = ['last_seen_at' => now(), 'last_ip' => $ip];
        if ($deviceId !== '' && $device->device_id !== $deviceId) {
            $updates['device_id'] = $deviceId;
        }
        if ($fingerprint !== '' && $device->fingerprint !== $fingerprint) {
            $updates['fingerprint'] = $fingerprint;
        }
        $device->update($updates);
        $device->recordActivity($ip);

        Log::info('TableroPublic: reconexión automática', [
            'device_id' => $device->id,
            'name'      => $device->name,
            'ip'        => $ip,
            'via'       => $via,
        ]);

        return response()->json([
            'success'       => true,
            'device_secret' => $device->device_secret,
            'name'          => $device->name,
            'sede'          => $device->sede_filter,
            'via'           => $via,
            'message'       => 'Reconectado automáticamente.',
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
     * Consulta al endpoint dedicado de urgencias en LH_INTEGRATIONS.
     *
     * Cambio 2026-08: antes se usaba `/api/data/dynamic` contra
     * `ug.VW_HC_TableroUrgencias` (LH_MEDILASER_ANALYTICS, compartido con 3.311 vistas).
     * Ahora usa `/api/urgencias/tablero` (LH_INTEGRATIONS, aislado, sin contención).
     */
    private function fetchData(TableroDevice $device): array
    {
        // config() y no env(): en produccion la config se cachea con
        // `php artisan config:cache`, y entonces env() dentro del controlador
        // devuelve null → la URL caia al default 127.0.0.1:8001 y la peticion no
        // llegaba a Graph-Fabric (que ademas rechaza sin X-API-Key). config/fabric.php
        // resuelve la URL, el token y la api_key con sus fallbacks.
        $url   = rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $token = (string) config('fabric.token_admin', '');
        $apiKey = (string) config('fabric.api_key', '');

        // Clave del ultimo dato bueno en cache, por sede (o global si no filtra).
        // Es la red de seguridad contra los picos de latencia de Python: si una
        // peticion falla, la TV sigue mostrando el ultimo dato en vez de ponerse
        // en rojo con la tabla vacia (igual que Excel Online mantiene lo ultimo).
        $cacheKey = 'tablero_urgencias_last_ok:' . strtoupper(trim((string) ($device->sede_filter ?: 'ALL')));

        // Diagnostico de por que fallo, para el frontend/log. No se muestra en TV.
        $motivo = null;

        try {
            // Timeouts mas holgados: la vista de urgencias puede tardar varios
            // segundos bajo carga. Antes 20s de read y 5s de connect cortaban
            // peticiones legitimas y dejaban la TV en rojo de forma intermitente.
            // Un reintento cubre el fallo puntual (Python arrancando una query).
            $response = Http::timeout(40)
                ->connectTimeout(10)
                ->retry(2, 1500, throw: false)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($url . '/api/urgencias/tablero', [
                    'token' => $token,
                ]);

            if ($response->failed()) {
                Log::warning('TableroPublic: endpoint urgencias fallo', [
                    'device'  => $device->id,
                    'url'     => $url . '/api/urgencias/tablero',
                    'status'  => $response->status(),
                    'has_key' => $apiKey !== '',
                    'body'    => substr($response->body(), 0, 200),
                ]);
                $motivo = 'python_http_' . $response->status();
                return $this->fallbackFromCache($device, $cacheKey, $motivo);
            }

            $allData = $response->json('data') ?? $response->json('items') ?? [];

            // Filtrar por sede si el dispositivo tiene filtro configurado
            if ($device->sede_filter && $device->sede_filter !== '' && !empty($allData)) {
                // Comparacion tolerante: se recorta el espacio de los dos lados.
                // Con strcasecmp a secas, un " TUNJA " en la vista no casaba con
                // "TUNJA" guardado en el dispositivo y la TV salia en blanco.
                $objetivo = strtoupper(trim((string) $device->sede_filter));

                $filtered = array_values(array_filter(
                    $allData,
                    fn ($row) => strtoupper(trim((string) ($row['Sede'] ?? ''))) === $objetivo
                ));
                $allData = $filtered;
            }

            $payload = [
                'success'   => true,
                'data'      => $allData,
                'sede'      => $device->sede_filter,
                'timestamp' => now()->toIso8601String(),
                'source'    => $response->header('X-Source') ?? 'LH_INTEGRATIONS',
                'elapsed'   => $response->header('X-Elapsed-Ms'),
            ];

            // Guardar como "ultimo dato bueno" 10 min. Solo si trajo filas: una
            // respuesta vacia legitima (sin pacientes) no debe pisar un cache util.
            if (!empty($allData)) {
                Cache::put($cacheKey, $payload, now()->addMinutes(10));
            }

            return $payload;
        } catch (\Throwable $e) {
            Log::warning('TableroPublic: error', ['device' => $device->id, 'error' => $e->getMessage()]);
            $motivo = 'exception';
            return $this->fallbackFromCache($device, $cacheKey, $motivo);
        }
    }

    /**
     * Cuando Python no responde, sirve el ultimo dato bueno cacheado en vez de
     * vaciar la pantalla. Marca `stale:true` para que el frontend sepa que no es
     * fresco, pero success:true para que la TV NO se ponga en rojo por un pico.
     *
     * Solo si no hay cache se devuelve success:false (arranque en frio con
     * Python caido): ahi el frontend reintenta con backoff.
     */
    private function fallbackFromCache(TableroDevice $device, string $cacheKey, ?string $motivo): array
    {
        $last = Cache::get($cacheKey);

        if (is_array($last) && !empty($last['data'])) {
            return array_merge($last, [
                'stale'     => true,
                'reason'    => $motivo,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return [
            'success'   => false,
            'data'      => [],
            'sede'      => $device->sede_filter,
            'reason'    => $motivo,
            'timestamp' => now()->toIso8601String(),
        ];
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
