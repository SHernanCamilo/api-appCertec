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
 * Límites por tipo de operación (por usuario/minuto):
 *   - /viewer/views:    60/min   (catálogo, se cachea 5 min)
 *   - /viewer/columns:  60/min   (columnas, se cachea)
 *   - /viewer/data:    200/min   (paginación intensiva con 500 usuarios)
 *   - /viewer/export:   20/min   (costoso pero necesario)
 *   - /viewer/context:  60/min
 *   - /viewer/start:    20/min   (export async)
 *
 * Con 500 usuarios simultáneos y cache activo:
 *   - Cache absorbe ~70% de queries repetidas (Redis TTL 30s-5min)
 *   - La API Python soporta 20 queries concurrentes (semáforo interno)
 *   - El circuit breaker protege contra sobrecarga
 */
final class FabricRateLimiter
{
    private const LIMITS = [
        'views'   => ['max' => 60,  'decay' => 60],
        'columns' => ['max' => 60,  'decay' => 60],
        'data'    => ['max' => 200, 'decay' => 60],
        'export'  => ['max' => 20,  'decay' => 60],
        'context' => ['max' => 60,  'decay' => 60],
        'start'   => ['max' => 20,  'decay' => 60],
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
