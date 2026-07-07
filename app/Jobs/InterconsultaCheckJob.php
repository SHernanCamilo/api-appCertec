<?php

namespace App\Jobs;

use App\Services\Notificaciones\InterconsultaNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InterconsultaCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 1;
    public $timeout = 120;

    public function handle(InterconsultaNotificationService $service): void
    {
        Log::channel('notificaciones')->info('[JOB] InterconsultaCheckJob ejecutándose...');
        $service->checkAndSendEmails();
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('notificaciones')->error('[JOB] InterconsultaCheckJob falló: ' . $exception->getMessage());
    }
}

