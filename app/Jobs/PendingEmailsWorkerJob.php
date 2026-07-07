<?php

namespace App\Jobs;

use App\Models\Notificaciones\NotifEmailLog;
use App\Models\Notificaciones\NotifEmailTrace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Worker de recuperación de emails pendientes.
 *
 * Resuelve emails que quedaron en status='SENT' + delivery_status='PENDING'
 * por más de X minutos (el bounce checker no los resolvió).
 *
 * Estrategia:
 * - Emails SENT + PENDING > 5 min → marcar como DELIVERED (si no rebotó en 5min, llegó)
 * - Emails status PENDING > 10 min → marcar como EXPIRED (nunca se enviaron)
 * - Emails SENT + PENDING > 24h → marcar como DELIVERED (caso extremo)
 */
class PendingEmailsWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 1;
    public $timeout = 60;

    public function handle(): void
    {
        Log::channel('notificaciones')->info('[WORKER] PendingEmailsWorkerJob ejecutándose...');

        try {
            // Primero: verificar rebotes con Microsoft Graph API
            $bounceChecker = app(\App\Services\Notificaciones\GraphBounceCheckerService::class);
            $bounceResult  = $bounceChecker->checkAllPending();

            if ($bounceResult['checked'] > 0) {
                Log::channel('notificaciones')->info("[WORKER] Bounce check: " . json_encode($bounceResult));
            }

            // Segundo: resolver los que quedaron pendientes (fallback por tiempo)
            $this->resolveDelivered();
            $this->resolveExpired();
            $this->resolveOldPending();
        } catch (\Exception $e) {
            Log::channel('notificaciones')->error('[WORKER] Error procesando pendientes: ' . $e->getMessage());
        }
    }

    /**
     * Emails enviados (SENT) con delivery PENDING por más de 5 minutos.
     * Si no rebotó en 5 min, se considera entregado.
     */
    private function resolveDelivered(): void
    {
        $fiveMinutesAgo    = Carbon::now()->subMinutes(5);
        $twentyFourHoursAgo = Carbon::now()->subHours(24);

        $logs = NotifEmailLog::where('status', NotifEmailLog::STATUS_SENT)
            ->where('delivery_status', NotifEmailLog::DELIVERY_PENDING)
            ->where('fecha_intento', '<', $fiveMinutesAgo)
            ->where('fecha_intento', '>', $twentyFourHoursAgo)
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        foreach ($logs as $log) {
            $log->update([
                'delivery_status' => NotifEmailLog::DELIVERY_DELIVERED,
                'delivered_at'    => now(),
            ]);

            NotifEmailTrace::create([
                'email_log_id'  => $log->id,
                'event_type'    => 'ENTREGADO',
                'event_status'  => 'SUCCESS',
                'event_message' => 'Email considerado entregado (>5min sin rebote) [Worker]',
                'created_at'    => now(),
            ]);
        }

        Log::channel('notificaciones')->info("[WORKER] {$logs->count()} email(s) marcados como DELIVERED (>5min sin rebote)");
    }

    /**
     * Emails que nunca se enviaron (status=PENDING) por más de 10 minutos.
     * Probablemente el proceso murió antes de enviar.
     */
    private function resolveExpired(): void
    {
        $tenMinutesAgo = Carbon::now()->subMinutes(10);

        $logs = NotifEmailLog::where('status', NotifEmailLog::STATUS_PENDING)
            ->where('fecha_envio', '<', $tenMinutesAgo)
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        foreach ($logs as $log) {
            $log->update([
                'status'          => NotifEmailLog::STATUS_EXPIRED,
                'delivery_status' => NotifEmailLog::DELIVERY_FAILED,
                'error_message'   => 'No se envió - proceso interrumpido antes del envío',
            ]);

            NotifEmailTrace::create([
                'email_log_id'  => $log->id,
                'event_type'    => 'ERROR',
                'event_status'  => 'WARNING',
                'event_message' => 'Email marcado como EXPIRED (nunca se envió, >10min) [Worker]',
                'created_at'    => now(),
            ]);
        }

        Log::channel('notificaciones')->warning("[WORKER] {$logs->count()} email(s) marcados como EXPIRED (nunca se enviaron)");
    }

    /**
     * Emails SENT + PENDING con más de 24 horas (caso extremo).
     */
    private function resolveOldPending(): void
    {
        $twentyFourHoursAgo = Carbon::now()->subHours(24);

        $logs = NotifEmailLog::where('status', NotifEmailLog::STATUS_SENT)
            ->where('delivery_status', NotifEmailLog::DELIVERY_PENDING)
            ->where('fecha_intento', '<', $twentyFourHoursAgo)
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        foreach ($logs as $log) {
            $log->update([
                'delivery_status' => NotifEmailLog::DELIVERY_DELIVERED,
                'delivered_at'    => now(),
            ]);

            NotifEmailTrace::create([
                'email_log_id'  => $log->id,
                'event_type'    => 'ENTREGADO',
                'event_status'  => 'SUCCESS',
                'event_message' => 'Email antiguo (>24h) marcado como entregado [Worker]',
                'created_at'    => now(),
            ]);
        }

        Log::channel('notificaciones')->info("[WORKER] {$logs->count()} email(s) antiguos (>24h) marcados como DELIVERED");
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('notificaciones')->error('[WORKER] PendingEmailsWorkerJob falló: ' . $exception->getMessage());
    }
}

