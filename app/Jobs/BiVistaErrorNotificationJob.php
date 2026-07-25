<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BiVistaErrorLog;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Notifica por email a los admins BI cuando una vista entra en mantenimiento automatico.
 * Se despacha desde BiVistaErrorLog cuando se detectan 3+ errores en 10 min.
 */
class BiVistaErrorNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        private readonly string $schema,
        private readonly string $view,
        private readonly string $errorType,
        private readonly string $errorMessage,
        private readonly int $errorCount,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        // Obtener admins BI (perfil "Inteligencia de Negocios - Admin")
        $admins = User::whereHas('roles', function ($q) {
            $q->where('name', 'like', '%inteligencia%nego%admin%')
              ->orWhere('name', 'like', '%bi%admin%')
              ->orWhere('name', 'like', '%super-admin%');
        })->pluck('email')->toArray();

        // Fallback si no encuentra por rol
        if (empty($admins)) {
            $admins = User::whereHas('perfiles', function ($q) {
                $q->where('nombre', 'like', '%inteligencia%nego%admin%');
            })->pluck('email')->toArray();
        }

        if (empty($admins)) {
            Log::warning('BiVistaErrorNotification: No se encontraron admins BI para notificar');
            return;
        }

        $subject = "[ALERTA BI] Vista en mantenimiento: {$this->schema}.{$this->view}";

        $body = "Se ha detectado un problema recurrente en la vista:\n\n"
            . "Vista: {$this->schema}.{$this->view}\n"
            . "Tipo de error: {$this->errorType}\n"
            . "Errores en ultimos 10 min: {$this->errorCount}\n"
            . "Ultimo error: {$this->errorMessage}\n\n"
            . "La vista se ha puesto automaticamente en estado MANTENIMIENTO.\n"
            . "Los usuarios veran un aviso indicando que la vista no esta disponible temporalmente.\n\n"
            . "Para reactivarla, vaya a: Inteligencia de Negocios > Metricas > Logs\n"
            . "O desde Parametros > Esquemas > seleccione la vista y cambie el estado a Activo.\n\n"
            . "— Sistema JadeOne";

        try {
            Mail::raw($body, function ($msg) use ($admins, $subject) {
                $msg->to($admins)
                    ->subject($subject);
            });

            // Marcar los logs como notificados
            BiVistaErrorLog::where('schema_name', $this->schema)
                ->where('view_name', $this->view)
                ->where('notification_sent', false)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->update(['notification_sent' => true]);

            Log::info('BiVistaErrorNotification enviada', [
                'vista' => "{$this->schema}.{$this->view}",
                'admins' => $admins,
            ]);
        } catch (\Throwable $e) {
            Log::error('BiVistaErrorNotification fallo', [
                'error' => $e->getMessage(),
                'vista' => "{$this->schema}.{$this->view}",
            ]);
        }
    }
}
