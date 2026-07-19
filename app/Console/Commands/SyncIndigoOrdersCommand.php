<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Inventory\MonitoringService;

class SyncIndigoOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:sync-indigo {--user=1 : ID del usuario que ejecuta la accion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza órdenes de compra desde el ERP Indigo (SQL Server) hacia la base local';

    /**
     * Execute the console command.
     */
    public function handle(MonitoringService $monitoringService)
    {
        $this->info('Sincronización Indigo INACTIVADA temporalmente.');
        // $this->info('Iniciando sincronización con Indigo ERP...');
        // $userId = (int) $this->option('user');
        // $result = $monitoringService->syncIndigoOrders($userId);
        // if ($result['success']) {
        //     $this->info($result['message']);
        //     $stats = $result['stats'];
        //     $this->table(
        //         ['Nuevas', 'Actualizadas', 'Total Procesadas'],
        //         [[$stats['nuevas'], $stats['actualizadas'], $stats['procesadas']]]
        //     );
        //     return Command::SUCCESS;
        // } else {
        //     $this->error('Error en sincronización: ' . $result['message']);
        //     $this->error($result['error'] ?? '');
        //     return Command::FAILURE;
        // }
        return Command::SUCCESS;
    }
}