<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limitador de CONCURRENCIA para consultas pesadas de Fabric.
 *
 * A diferencia del rate limiter (peticiones/minuto), este controla cuántas
 * consultas pesadas corren SIMULTÁNEAMENTE. Previene que una ráfaga de
 * consultas lentas (vistas de 3 min) agote todos los workers de PHP-FPM
 * y deje la API colgada para todos.
 *
 * Usa un sorted set de Redis con auto-limpieza:
 *   - Cada request en curso agrega un miembro con score = timestamp
 *   - Antes de contar, purga miembros más viejos que MAX_HOLD_SECONDS
 *     (self-healing: si un worker muere, su slot se libera solo)
 *   - Si hay demasiadas activas → 429 inmediato (no bloquea)
 */
final class FabricConcurrencyLimiter
{
    /** Clave del sorted set en Redis. */
    private const ZSET_KEY = 'fabric:concurrency:heavy';

    public function handle(Request $request, Closure $next): Response
    {
        // Configurable por .env sin re-desplegar. Debe ser MENOR que pm.max_children.
        $maxConcurrent  = (int) env('FABRIC_MAX_CONCURRENT', 8);
        $maxHoldSeconds = (int) env('FABRIC_MAX_HOLD_SECONDS', 200);

        $now    = microtime(true);
        $slotId = uniqid('', true) . '-' . Str::random(6);

        // Purgar slots abandonados (workers que murieron sin liberar)
        Redis::zremrangebyscore(self::ZSET_KEY, '-inf', (string) ($now - $maxHoldSeconds));

        // Contar consultas pesadas activas
        $active = (int) Redis::zcard(self::ZSET_KEY);

        if ($active >= $maxConcurrent) {
            return response()->json([
                'success'     => false,
                'message'     => 'El sistema está procesando muchas consultas pesadas en este momento. '
                               . 'Intente nuevamente en unos segundos.',
                'retry_after' => 5,
                'code'        => 'fabric_busy',
            ], 429);
        }

        // Reservar slot
        Redis::zadd(self::ZSET_KEY, $now, $slotId);
        // TTL de seguridad al set completo (por si queda huérfano)
        Redis::expire(self::ZSET_KEY, $maxHoldSeconds + 60);

        try {
            return $next($request);
        } finally {
            // Liberar slot siempre, incluso si hubo excepción
            Redis::zrem(self::ZSET_KEY, $slotId);
        }
    }
}
