<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Inventory\MonitoringService;
use Illuminate\Support\Facades\Log;

class SyncIndigoOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indigo:sync-orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza las Órdenes de Compra desde Indigo ERP hacia api-appCertec en segundo plano';

    /**
     * Execute the console command.
     */
    public function handle(MonitoringService $monitoringService)
    {
        $this->info('Sincronización Indigo INACTIVADA temporalmente.');
        // Log::channel('daily')->info('[INDIGO-SYNC] Iniciando tarea programada de sincronización de Órdenes de Compra.');
        // try {
        //     $result = $monitoringService->syncIndigoOrders();
        //     if ($result['success']) {
        //         $this->info($result['message']);
        //         $stats = $result['stats'] ?? [];
        //         Log::channel('daily')->info('[INDIGO-SYNC] Sincronización exitosa.', $stats);
        //         $this->table(
        //             ['Procesadas', 'Nuevas', 'Actualizadas'],
        //             [[$stats['procesadas'] ?? 0, $stats['nuevas'] ?? 0, $stats['actualizadas'] ?? 0]]
        //         );
        //     } else {
        //         $this->error('Error en sincronización: ' . $result['message']);
        //         Log::channel('daily')->error('[INDIGO-SYNC] Error: ' . $result['message']);
        //     }
        // } catch (\Exception $e) {
        //     $this->error('Ocurrió una excepción: ' . $e->getMessage());
        //     Log::channel('daily')->error('[INDIGO-SYNC] Excepción crítica: ' . $e->getMessage());
        //     return Command::FAILURE;
        // }
        // $this->info('Sincronización finalizada.');
        return Command::SUCCESS;
    }
}
