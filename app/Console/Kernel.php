<?php

namespace App\Console;

use App\Jobs\InterconsultaCheckJob;
use App\Jobs\PendingEmailsWorkerJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Ejecutar tareas recurrentes cada minuto
        $schedule->command('tasks:run-recurring')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Sincronizar festivos el 1° de cada mes a las 02:00 (+ próximo año)
        $schedule->command('festivos:sincronizar --next')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping();

        // ── Notificaciones de Interconsultas ─────────────────────────────────
        // Consulta Fabric y envía emails de nuevas interconsultas cada 5 min
        $schedule->job(new InterconsultaCheckJob)
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Resuelve emails pendientes (DELIVERED/EXPIRED) cada 5 min
        $schedule->job(new PendingEmailsWorkerJob)
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // ── Sincronización ERP Indigo ────────────────────────────────────────
        // Sincroniza las órdenes de compra desde la vista de Indigo cada 10 min
        // INACTIVADO temporalmente por error de driver sqlsrv (pdo_odbc no disponible)
        // $schedule->command('indigo:sync-orders')
        //     ->everyTenMinutes()
        //     ->withoutOverlapping()
        //     ->appendOutputTo(storage_path('logs/indigo-sync.log'));

        // ── OData Cache Warmup ───────────────────────────────────────────────
        // Pre-calienta Redis con las vistas OData más consultadas (top 10, 3 páginas)
        // Cuando Excel refresque, la respuesta sale de cache → 0 espera en Fabric
        $schedule->command('odata:warm-cache --top=10 --pages=3')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/odata-warm-cache.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
