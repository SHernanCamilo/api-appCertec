<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker para la API Graph-Fabric.
 *
 * Previene saturación cuando la API Python no responde:
 *   - CLOSED (normal):     requests fluyen normalmente
 *   - OPEN (cortado):      rejects inmediatos sin tocar la API (60s)
 *   - HALF_OPEN (prueba):  deja pasar 1 request para verificar si se recuperó
 *
 * Config:
 *   - threshold: N fallos consecutivos para abrir el circuito (default 5)
 *   - timeout: segundos que permanece abierto antes de probar (default 60)
 */
final class FabricCircuitBreaker
{
    private const CACHE_KEY_FAILURES = 'fabric_cb:failures';
    private const CACHE_KEY_OPENED   = 'fabric_cb:opened_at';
    private const CACHE_KEY_STATE    = 'fabric_cb:state';

    private const STATE_CLOSED    = 'closed';
    private const STATE_OPEN      = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private int $threshold;
    private int $timeout;

    public function __construct()
    {
        $this->threshold = (int) env('FABRIC_CB_THRESHOLD', 5);
        $this->timeout   = (int) env('FABRIC_CB_TIMEOUT', 60);
    }

    /**
     * ¿Puede pasar el request?
     */
    public function isAvailable(): bool
    {
        $state = $this->getState();

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            $openedAt = (int) Cache::get(self::CACHE_KEY_OPENED, 0);
            $elapsed  = time() - $openedAt;

            if ($elapsed >= $this->timeout) {
                // Transición a half_open: dejar pasar 1 request de prueba
                $this->setState(self::STATE_HALF_OPEN);
                return true;
            }

            return false; // Sigue abierto, reject inmediato
        }

        // HALF_OPEN: dejar pasar
        return true;
    }

    /**
     * Registra un éxito → resetea el circuito a CLOSED.
     */
    public function recordSuccess(): void
    {
        Cache::put(self::CACHE_KEY_FAILURES, 0, 300);
        $this->setState(self::STATE_CLOSED);
    }

    /**
     * Registra un fallo → incrementa contador → abre circuito si supera threshold.
     */
    public function recordFailure(): void
    {
        $failures = (int) Cache::get(self::CACHE_KEY_FAILURES, 0) + 1;
        Cache::put(self::CACHE_KEY_FAILURES, $failures, 300);

        if ($failures >= $this->threshold) {
            $this->setState(self::STATE_OPEN);
            Cache::put(self::CACHE_KEY_OPENED, time(), $this->timeout + 30);

            Log::warning('FabricCircuitBreaker: circuito ABIERTO', [
                'failures'  => $failures,
                'threshold' => $this->threshold,
                'timeout_s' => $this->timeout,
            ]);
        }
    }

    /**
     * Retorna el estado actual y métricas para debugging.
     */
    public function getStatus(): array
    {
        return [
            'state'    => $this->getState(),
            'failures' => (int) Cache::get(self::CACHE_KEY_FAILURES, 0),
            'threshold' => $this->threshold,
            'timeout'   => $this->timeout,
        ];
    }

    /**
     * Reset manual del circuito.
     */
    public function reset(): void
    {
        Cache::forget(self::CACHE_KEY_FAILURES);
        Cache::forget(self::CACHE_KEY_OPENED);
        $this->setState(self::STATE_CLOSED);
    }

    private function getState(): string
    {
        return Cache::get(self::CACHE_KEY_STATE, self::STATE_CLOSED);
    }

    private function setState(string $state): void
    {
        Cache::put(self::CACHE_KEY_STATE, $state, 300);
    }
}
