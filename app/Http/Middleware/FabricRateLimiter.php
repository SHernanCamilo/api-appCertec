<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiter específico para endpoints Fabric.
 *
 * Límites por tipo de operación:
 *   - /viewer/views:    10/min/usuario  (se cachea, no necesita más)
 *   - /viewer/columns:  20/min/usuario  (se cachea)
 *   - /viewer/data:     30/min/usuario  (paginación + filtros)
 *   - /viewer/export:    3/min/usuario  (costoso, consume RAM)
 *
 * Con 500 usuarios simultáneos y estos límites:
 *   - Máx queries reales a la API Py: ~250/min (con cache absorbe el resto)
 *   - Máx exports simultáneos: ~15 (controlados)
 */
final class FabricRateLimiter
{
    private const LIMITS = [
        'views'   => ['max' => 30, 'decay' => 60],
        'columns' => ['max' => 20, 'decay' => 60],
        'data'    => ['max' => 30, 'decay' => 60],
        'export'  => ['max' => 3,  'decay' => 60],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        // Detectar tipo de operación por el último segmento de la ruta
        $segments = $request->segments();
        $action   = end($segments);

        $config = self::LIMITS[$action] ?? self::LIMITS['data'];
        $key    = "fabric:{$action}:" . $user->id;

        if (RateLimiter::tooManyAttempts($key, $config['max'])) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'success'     => false,
                'message'     => "Rate limit alcanzado. Máximo {$config['max']} peticiones por minuto para esta operación.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        RateLimiter::hit($key, $config['decay']);

        return $next($request);
    }
}
