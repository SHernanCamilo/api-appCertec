<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Inventory\Pharmacy\PharmacyMigrationService;

/**
 * Migra datos desde la base de datos Digipharma (192.168.12.20) hacia la VPS.
 *
 * Requiere configurar la conexión 'digipharma' en config/database.php:
 *
 * 'digipharma' => [
 *     'driver' => 'mysql',
 *     'host' => '192.168.12.20',
 *     'port' => '3306',
 *     'database' => 'digipharma',
 *     'username' => 'digipharma_app',
 *     'password' => 'kD21c2P7wQW9',
 *     'charset' => 'utf8mb4',
 *     'collation' => 'utf8mb4_unicode_ci',
 * ],
 */
class MigrateDigipharmaData extends Command
{
    protected $signature = 'pharmacy:migrate-digipharma 
                            {--dry-run : Solo mostrar qué se haría sin ejecutar}
                            {--user=1 : ID del usuario admin en la VPS}';

    protected $description = 'Migra datos de inventario/farmacia desde Digipharma (192.168.12.20) hacia la VPS';

    public function handle(PharmacyMigrationService $migrationService): int
    {
        if ($this->option('dry-run')) {
            $this->warn('MODO DRY-RUN: No se ejecutarán cambios.');
            $this->info('Se migrarían las siguientes tablas:');
            $this->table(
                ['Tabla Origen', 'Tabla Destino'],
                [
                    ['pedidos', 'inv_pedidos'],
                    ['pedidos_detalle', 'inv_pedido_detalles'],
                    ['compras', 'inv_ordenes_compra'],
                    ['compras_detalle', 'inv_orden_compra_detalles'],
                    ['compras_pedidos', 'inv_compras_pedidos'],
                    ['indigo_ordenes_items', 'inv_indigo_items'],
                    ['indigo_ordenes_eventos', 'inv_indigo_eventos'],
                    ['recepciones_historico', 'inv_recepciones'],
                    ['recepciones_historico_detalle', 'inv_recepcion_detalles'],
                    ['formula_magistral_muestra', 'inv_muestreo_niveles'],
                    ['formula_magistral_muestra_exclusion', 'inv_muestreo_exclusiones'],
                ]
            );
            return 0;
        }

        $this->info('=== Migración Digipharma → VPS ===');
        $this->warn('Origen: 192.168.12.20 (digipharma)');
        $this->warn('Destino: VPS (medadminvps_Jade-plataform)');

        if (!$this->confirm('¿Desea continuar con la migración?')) {
            $this->info('Migración cancelada.');
            return 0;
        }

        $this->info('Iniciando migración...');
        $bar = $this->output->createProgressBar(11);
        $bar->start();

        $stats = $migrationService->migrateAll((int) $this->option('user'));

        $bar->finish();
        $this->newLine(2);

        $this->info('=== Resultados ===');
        $this->table(
            ['Tabla', 'Registros migrados'],
            [
                ['Pedidos', $stats['pedidos']],
                ['Pedidos Detalle', $stats['pedidos_detalle']],
                ['Órdenes de Compra', $stats['compras']],
                ['Compras Detalle', $stats['compras_detalle']],
                ['Compras-Pedidos', $stats['compras_pedidos']],
                ['Indigo Items', $stats['indigo_items']],
                ['Indigo Eventos', $stats['indigo_eventos']],
                ['Recepciones', $stats['recepciones']],
                ['Recepciones Detalle', $stats['recepciones_detalle']],
                ['Muestreo Niveles', $stats['muestreo_niveles']],
                ['Muestreo Exclusiones', $stats['muestreo_exclusiones']],
            ]
        );

        if (!empty($stats['errores'])) {
            $this->error('Errores encontrados:');
            foreach ($stats['errores'] as $error) {
                $this->line("  - {$error}");
            }
            return 1;
        }

        $this->info('Migración completada exitosamente.');
        return 0;
    }
}
