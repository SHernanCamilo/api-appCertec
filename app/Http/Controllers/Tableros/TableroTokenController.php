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

/**
 * CRUD de dispositivos de tablero (requiere auth:api).
 *
 * Permite crear tableros (genera código de emparejamiento), listar TVs
 * conectadas, y revocar acceso.
 */
final class TableroTokenController extends Controller
{
    /**
     * GET /api/tableros/tokens — Listar todos los dispositivos
     */
    public function index(): JsonResponse
    {
        $devices = TableroDevice::orderByDesc('created_at')
            ->get([
                'id', 'name', 'schema_name', 'view_name', 'sede_filter',
                'paired', 'active', 'pairing_code', 'pairing_expires_at',
                'last_seen_at', 'last_ip', 'user_agent',
                'connection_count', 'max_connections', 'created_at',
            ]);

        // Mostrar el código solo si aún no fue emparejado y está vigente
        $data = $devices->map(function ($d) {
            $arr = $d->toArray();
            if ($d->paired || ($d->pairing_expires_at && $d->pairing_expires_at->isPast())) {
                $arr['pairing_code'] = null;
            }
            return $arr;
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/tableros/tokens/sedes — Sedes que trae la vista del tablero
     *
     * El desplegable "Sede" del administrador estaba escrito a mano en el
     * frontend (NEIVA, FLORENCIA, FACATATIVA, PITALITO, GARZON), asi que no
     * coincidia con la realidad: faltaba TUNJA y sobraban sedes que la vista no
     * devuelve. Cada apertura de sede obligaba a tocar codigo y desplegar.
     *
     * Ahora las sedes salen de los MISMOS datos que pinta el tablero
     * ([UG].[VW_HC_TableroUrgencias] via LH_INTEGRATIONS), asi que la lista se
     * actualiza sola.
     */
    public function sedes(): JsonResponse
    {
        // 10 min: las sedes cambian con muy poca frecuencia y esto es un
        // desplegable de administracion, no el tablero en vivo.
        $sedes = Cache::remember('tablero_sedes_disponibles', 600, function (): array {
            return $this->fetchSedesFromView();
        });

        // Sedes ya usadas por dispositivos existentes: se suman aunque la vista
        // no las devuelva ahora mismo (p. ej. sin pacientes en este momento),
        // para no perder el filtro de un tablero ya configurado.
        $enUso = TableroDevice::query()
            ->whereNotNull('sede_filter')
            ->where('sede_filter', '!=', '')
            ->distinct()
            ->pluck('sede_filter')
            ->map(fn ($s) => strtoupper(trim((string) $s)))
            ->all();

        $todas = collect($sedes)->merge($enUso)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data'    => $todas,
            'source'  => count($sedes) > 0 ? 'vista' : 'dispositivos',
        ]);
    }

    /**
     * Lee las sedes distintas del endpoint de urgencias de la API Python.
     * Devuelve [] si el servicio no responde: el frontend seguira mostrando las
     * sedes en uso, y el administrador siempre puede dejar "Todas las sedes".
     */
    private function fetchSedesFromView(): array
    {
        try {
            $url    = rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/');
            $token  = (string) config('fabric.token_admin', '');
            $apiKey = (string) config('fabric.api_key', '');

            $response = Http::timeout(20)
                ->connectTimeout(8)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($url . '/api/urgencias/tablero', ['token' => $token]);

            if ($response->failed()) {
                Log::warning('TableroTokens/sedes: API Python no respondio', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            $rows = $response->json('data') ?? $response->json('items') ?? [];

            return collect($rows)
                ->pluck('Sede')
                ->map(fn ($s) => strtoupper(trim((string) $s)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('TableroTokens/sedes: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * GET /api/tableros/tokens/diagnostico — Diagnostico de la conexion a Graph-Fabric
     *
     * Sirve para confirmar en produccion, sin adivinar, si el tablero llega o no
     * a Graph-Fabric: muestra a QUE URL apunta Laravel, si lleva X-API-Key, el
     * status que devuelve Python y cuantas filas trae. Requiere auth:api (admin).
     *
     * No expone secretos: solo indica si estan presentes y su longitud.
     */
    public function diagnostico(): JsonResponse
    {
        $url    = rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $token  = (string) config('fabric.token_admin', '');
        $apiKey = (string) config('fabric.api_key', '');
        $endpoint = $url . '/api/urgencias/tablero';

        // Aviso si la config esta cacheada con env vacio: la causa clasica de que
        // "no llega ninguna peticion a Graph-Fabric".
        $configCacheada = app()->configurationIsCached();

        $out = [
            'success'          => true,
            'url'              => $url,
            'endpoint'         => $endpoint,
            'apuntando_local'  => str_contains($url, '127.0.0.1') || str_contains($url, 'localhost'),
            'x_api_key'        => $apiKey !== '' ? 'presente (' . strlen($apiKey) . ' chars)' : 'FALTA',
            'token_admin'      => $token !== '' ? 'presente (' . strlen($token) . ' chars)' : 'FALTA',
            'config_cacheada'  => $configCacheada,
        ];

        try {
            $t0 = microtime(true);
            $response = Http::timeout(20)
                ->connectTimeout(8)
                ->acceptJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($endpoint, ['token' => $token]);
            $ms = (int) round((microtime(true) - $t0) * 1000);

            $rows = $response->json('data') ?? $response->json('items') ?? [];

            $out['python'] = [
                'status'    => $response->status(),
                'ok'        => $response->successful(),
                'elapsed_ms'=> $ms,
                'x_source'  => $response->header('X-Source'),
                'filas'     => is_array($rows) ? count($rows) : 0,
                'sedes'     => is_array($rows)
                    ? collect($rows)->pluck('Sede')->unique()->values()->all()
                    : [],
                'body_muestra' => substr($response->body(), 0, 200),
            ];
        } catch (\Throwable $e) {
            $out['python'] = [
                'error' => get_class($e),
                'mensaje' => $e->getMessage(),
                'pista'   => 'No se pudo conectar. Revisar que ' . $url . ' sea alcanzable desde este servidor.',
            ];
        }

        return response()->json($out);
    }

    /**
     * POST /api/tableros/tokens — Crear un nuevo tablero (genera código de emparejamiento)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:150',
            'schema_name'     => 'nullable|string|max:10',
            'view_name'       => 'nullable|string|max:150',
            'sede_filter'     => 'nullable|string|max:100',
            'max_connections' => 'nullable|integer|min:1|max:10',
        ]);

        $code = TableroDevice::generatePairingCode();

        $device = TableroDevice::create([
            'pairing_code'       => $code,
            'pairing_expires_at' => now()->addMinutes(5),
            'name'               => $request->name,
            'schema_name'        => $request->input('schema_name', 'ug'),
            'view_name'          => $request->input('view_name', 'VW_HC_TableroUrgencias'),
            'sede_filter'        => $request->sede_filter,
            'max_connections'    => $request->input('max_connections', 2),
            'created_by'         => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'           => $device->id,
                'name'         => $device->name,
                'pairing_code' => $code,
                'expires_in'   => '5 minutos',
                'sede_filter'  => $device->sede_filter,
                'instructions' => "En la TV, navegue a jade.medilaser.com.co/tablero e ingrese el código: {$code}",
            ],
        ], 201);
    }

    /**
     * POST /api/tableros/tokens/{id}/regenerate-code — Generar nuevo código (si la TV no emparejó)
     */
    public function regenerateCode(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);

        if ($device->paired) {
            return response()->json([
                'success' => false,
                'message' => 'Este dispositivo ya fue emparejado. Revóquelo y cree uno nuevo si necesita re-emparejar.',
            ], 422);
        }

        $code = TableroDevice::generatePairingCode();
        $device->update([
            'pairing_code'       => $code,
            'pairing_expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'pairing_code' => $code,
                'expires_in'   => '5 minutos',
            ],
        ]);
    }

    /**
     * PATCH /api/tableros/tokens/{id}/revoke — Revocar acceso de una TV
     */
    public function revoke(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);
        $device->update(['active' => false]);

        return response()->json([
            'success' => true,
            'message' => "Tablero '{$device->name}' revocado. La TV dejará de recibir datos.",
        ]);
    }

    /**
     * PATCH /api/tableros/tokens/{id}/activate — Reactivar una TV
     */
    public function activate(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);
        $device->update(['active' => true]);

        return response()->json([
            'success' => true,
            'message' => "Tablero '{$device->name}' reactivado.",
        ]);
    }

    /**
     * DELETE /api/tableros/tokens/{id} — Eliminar permanentemente
     */
    public function destroy(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);
        $device->delete();

        return response()->json([
            'success' => true,
            'message' => "Tablero '{$device->name}' eliminado permanentemente.",
        ]);
    }
}
