<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Inventory\Pharmacy\MonitoringService;

/**
 * Sincroniza OC desde Indigo ERP hacia la BD local.
 *
 * Uso manual:
 *   php artisan inventory:sync-indigo
 *   php artisan inventory:sync-indigo --sucursal=2
 *   php artisan inventory:sync-indigo --numero-orden=0000133750 --sucursal=2
 *
 * Ejecutado también por el scheduler (Kernel.php) cada N minutos para
 * mantener las órdenes locales actualizadas sin intervención manual.
 *
 * La sucursal determina el prefijo del consecutivo (FLA, NVA, TJA, etc.).
 * Si no se indica, el consecutivo de OC nuevas usará la sucursal del usuario.
 */
class SyncIndigoOrdersCommand extends Command
{
    protected $signature = 'inventory:sync-indigo
                            {--user=1        : ID del usuario que ejecuta la sincronización}
                            {--sucursal=     : ID de la sucursal destino (config_ubi_sucursales)}
                            {--numero-orden= : Sincronizar solo esta orden Indigo}
                            {--fecha-desde=  : Fecha inicial para el rango (YYYY-MM-DD). Default: hoy - 7 días}
                            {--limit=2000    : Máximo de registros Indigo a procesar}';

    protected $description = 'Sincroniza Órdenes de Compra desde el ERP Indigo hacia la BD local';

    public function handle(MonitoringService $monitoringService): int
    {
        $userId     = (int) $this->option('user');
        $sucursalId = $this->option('sucursal') !== null ? (int) $this->option('sucursal') : null;
        $numOrden   = $this->option('numero-orden') ?: null;
        $fechaDesde = $this->option('fecha-desde') ?: null;
        $limit      = (int) $this->option('limit');

        $this->info('[INDIGO-SYNC] Iniciando sincronización...');
        $this->line("  → Usuario ID: {$userId}");
        $this->line("  → Sucursal ID: " . ($sucursalId ?? 'no especificada (se usará sucursal del usuario)'));
        if ($numOrden) $this->line("  → Número de orden: {$numOrden}");
        if ($fechaDesde) $this->line("  → Fecha desde: {$fechaDesde}");

        $options = ['limit' => $limit];
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
