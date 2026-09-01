<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Inventory\Pharmacy\MonitoringService;

/**
 * Sincroniza OC desde Indigo ERP hacia la BD local.
 *
 * Uso manual (desde consola):
 *   php artisan inventory:sync-indigo
 *   php artisan inventory:sync-indigo --numero-orden=0000133750
 *
 * Ejecutado por el scheduler (Kernel.php) cada N minutos con --auto para
 * mantener las OC locales actualizadas sin intervención humana.
 *
 * La sucursal de cada OC se DEDUCE automáticamente de la propia orden de Indigo
 * (prefijo del número interno FLA/NVA/TJA... o campo de sucursal de la vista).
 * --sucursal solo se usa como respaldo si una orden no permite deducirla.
 */
class SyncIndigoOrdersCommand extends Command
{
    protected $signature = 'inventory:sync-indigo
                            {--user=1        : ID del usuario (solo para sync manual; en --auto es referencia de sistema)}
                            {--sucursal=     : (Opcional) Sucursal de respaldo si no se puede deducir de la orden}
                            {--numero-orden= : Sincronizar solo esta orden Indigo}
                            {--fecha-desde=  : Fecha inicial para el rango (YYYY-MM-DD). Default: hoy - 7 días}
                            {--limit=2000    : Máximo de registros Indigo a procesar}
                            {--auto          : Marca la ejecución como automática (cron), para auditoría}';

    protected $description = 'Sincroniza Órdenes de Compra desde el ERP Indigo hacia la BD local';

    public function handle(MonitoringService $monitoringService): int
    {
        $userId     = (int) $this->option('user');
        $sucursalId = $this->option('sucursal') !== null && $this->option('sucursal') !== ''
            ? (int) $this->option('sucursal')
            : null;
        $numOrden   = $this->option('numero-orden') ?: null;
        $fechaDesde = $this->option('fecha-desde') ?: null;
        $limit      = (int) $this->option('limit');
        $esAuto     = (bool) $this->option('auto');

        $this->info('[INDIGO-SYNC] Iniciando sincronización ' . ($esAuto ? '(automática)' : '(manual)') . '...');
        $this->line("  → Sucursal por orden: se deduce automáticamente" . ($sucursalId ? " (respaldo: {$sucursalId})" : ''));
        if ($numOrden) $this->line("  → Número de orden: {$numOrden}");
        if ($fechaDesde) $this->line("  → Fecha desde: {$fechaDesde}");

        $options = ['limit' => $limit, 'auto' => $esAuto];
        if ($numOrden)   $options['numero_orden'] = $numOrden;
        if ($fechaDesde) $options['fecha_desde']  = $fechaDesde;
        if ($sucursalId) $options['sucursal_id']  = $sucursalId;

        $result = $monitoringService->syncIndigoOrders($userId, $options);

        if ($result['success']) {
            $this->info('[INDIGO-SYNC] ' . $result['message']);
            $stats = $result['stats'] ?? [];
            $this->table(
                ['Procesadas', 'Nuevas', 'Actualizadas', 'Devoluciones', 'Errores'],
                [[
                    $stats['procesadas']   ?? 0,
                    $stats['nuevas']       ?? 0,
                    $stats['actualizadas'] ?? 0,
                    $stats['devoluciones'] ?? 0,
                    $stats['errores']      ?? 0,
                ]]
            );
            return Command::SUCCESS;
        }

        $this->error('[INDIGO-SYNC] ' . $result['message']);
        return Command::FAILURE;
    }
}
