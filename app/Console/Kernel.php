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

        // ── OData Snapshot Cleanup ───────────────────────────────────────────
        // Borra los snapshots NDJSON que ya expiraron (libera disco).
        // Se regeneran solos en la siguiente petición, no rompe nada.
        $schedule->command('odata:snapshot-cleanup --hours=6')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // ── Fichas Técnicas: vigencias ───────────────────────────────────────
        // Promueve a `vigente` las fichas aprobadas cuya fecha_ini ya llegó.
        // La transición queda auditada en fich_historial_estados, a diferencia
        // del legacy, que solo comparaba fechas en cada consulta.
        $schedule->command('fichas:actualizar-vigencias')
            ->dailyAt('00:15')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/fichas-vigencias.log'));

        // ── Limpieza de exports (xlsx/csv) ───────────────────────────────
        // Elimina archivos de export que tienen más de 2 horas. Los exports
        // pesan entre 4 MB y 450 MB; sin limpieza el disco se llena rápido.
        $schedule->command('exports:cleanup --hours=2')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // ── Parquet: sincronizar config con Graph-Fabric ─────────────────
        // Reenvía la config de bi_parquet_config a Graph-Fabric cada 30 min
        // (por si Graph se reinició y perdió su schedule).
        $schedule->command('fabric:sync-parquet-config')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/parquet-sync.log'));

        // ── Parquet: snapshot de estado (trazabilidad) ───────────────────
        // Guarda en bi_parquet_history el estado de cada parquet cada 15 min.
        // Permite ver qué vistas nunca se regeneran, qué carril se represa, etc.
        $schedule->command('fabric:snapshot-parquet-status --prune=7')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/parquet-snapshot.log'));

        // ── Parquet: rebalanceo automático del scheduler ─────────────────
        // Semanal (domingo 03:00): reajusta intervalos según el tamaño real de
        // cada vista y desactiva las pequeñas del scheduler (se exportan al vuelo).
        // Los tamaños de vista cambian lento, por eso semanal es suficiente.
        $schedule->command('fabric:rebalance-scheduler --sync')
            ->weeklyOn(0, '03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/parquet-rebalance.log'));
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
