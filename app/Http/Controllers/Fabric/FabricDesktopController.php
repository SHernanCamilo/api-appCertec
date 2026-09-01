<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Arranque de JadeOne Desktop desde la web.
 *
 * El JWT no viaja en el protocolo (queda visible en historial / process list).
 * La web pide un ticket opaco de 60s / un solo uso; el .exe lo canjea por un JWT corto.
 */
class FabricDesktopController extends Controller
{
    private const TICKET_TTL_SECONDS = 60;
    private const DESKTOP_JWT_TTL_MINUTES = 120;
    private const CACHE_PREFIX = 'fabric_desktop_ticket:';
    private const SETUP_RELATIVE = 'desktop/JadeOneDesktop.exe';

    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {}

    /**
     * POST /api/fabric/viewer/desktop/launch  (auth:api)
     *
     * Body: { schema_name, view, view_label? }
     */
    public function launch(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string|max:20|alpha_dash',
            'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
            'view_label'  => 'nullable|string|max:200',
        ]);

        $user   = auth()->user();
        $schema = strtolower((string) $request->input('schema_name'));
        $view   = (string) $request->input('view');

        if (!$this->gateway->tieneAccesoEsquema($user, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso al esquema '{$schema}'.",
            ], 403);
        }

        if (!$this->gateway->tieneAccesoVistaPorSede($user, $view, $schema)) {
            return response()->json([
                'success' => false,
                'message' => "Sin acceso a la vista '{$view}' por sede.",
            ], 403);
        }

        $ticket = bin2hex(random_bytes(16));
        $label  = trim((string) $request->input('view_label', ''));

        Cache::put(self::CACHE_PREFIX . $ticket, [
            'user_id'     => $user->id,
            'schema'      => $schema,
            'view'        => $view,
            'view_label'  => $label !== '' ? $label : $view,
        ], self::TICKET_TTL_SECONDS);

        $apiUrl      = rtrim((string) config('app.url'), '/') . '/api';
        $env         = self::desktopEnvFromAppUrl((string) config('app.url'));
        $protocolUrl = 'jadeone-desktop://open?ticket=' . rawurlencode($ticket)
            . '&env=' . rawurlencode($env)
            . '&api=' . rawurlencode($apiUrl);

        return response()->json([
            'success'      => true,
            'ticket'       => $ticket,
            'protocol_url' => $protocolUrl,
            'download_url' => url('/api/fabric/viewer/desktop/download'),
            'expires_in'   => self::TICKET_TTL_SECONDS,
        ]);
    }

    /**
     * prod | local. El .exe mapea esto a un host conocido; no usa api= del protocolo.
     */
    private static function desktopEnvFromAppUrl(string $appUrl): string
    {
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        if ($host === '') {
            return 'prod';
        }

        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return 'local';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                return 'local';
            }
        }

        return 'prod';
    }

    /**
     * POST /api/fabric/viewer/desktop/claim  (público, rate-limited)
     *
     * Body: { ticket }
     */
    public function claim(Request $request): JsonResponse
    {
        $request->validate([
            'ticket' => 'required|string|size:32|regex:/^[a-f0-9]+$/',
        ]);

        $key     = self::CACHE_PREFIX . $request->input('ticket');
        $payload = Cache::pull($key);

        if (!is_array($payload) || empty($payload['user_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket inválido, expirado o ya utilizado. Vuelva a abrir la vista desde la plataforma.',
            ], 410);
        }

        $user = User::find($payload['user_id']);
        if (!$user || (method_exists($user, 'estaActivo') && !$user->estaActivo())) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta no está activa.',
            ], 403);
        }

        JWTAuth::factory()->setTTL(self::DESKTOP_JWT_TTL_MINUTES);
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success'     => true,
            'token'       => $token,
            'schema'      => $payload['schema'],
            'view'        => $payload['view'],
            'view_label'  => $payload['view_label'] ?? $payload['view'],
            'api_url'     => rtrim((string) config('app.url'), '/') . '/api',
            'user'        => $user->email ?? $user->name,
            'expires_in'  => self::DESKTOP_JWT_TTL_MINUTES * 60,
        ]);
    }

    /**
     * GET /api/fabric/viewer/desktop/download  (público)
     *
     * Sirve JadeOneDesktop.exe publicado en storage/app/desktop/.
     */
    public function download(): BinaryFileResponse|JsonResponse
    {
        $relative = self::SETUP_RELATIVE;
        $absolute = storage_path('app/' . $relative);

        if (!is_file($absolute)) {
            return response()->json([
                'success' => false,
                'message' => 'JadeOne Desktop aún no está publicado en el servidor. Ejecute scripts/publish.ps1 en sara-bi-desktop.',
            ], 404);
        }

        return response()->download($absolute, 'JadeOneDesktop.exe', [
            'Content-Type'        => 'application/vnd.microsoft.portable-executable',
            'Cache-Control'       => 'no-store, no-cache',
            'Content-Disposition' => 'attachment; filename="JadeOneDesktop.exe"',
        ]);
    }
}
