<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class MigratePedidosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:migrate-pedidos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate pedidos and pedidos_detalle from digipharma to jadeonedevs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Configuring digipharma connection...');

        // Configurar conexión a DB antigua en tiempo de ejecución
        Config::set('database.connections.digipharma', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'digipharma',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
        ]);

        $this->info('Truncating target tables in jadeonedevs...');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('inv_pedido_detalles')->truncate();
        DB::table('inv_pedidos')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info('Fetching old pedidos...');
        $oldPedidos = DB::connection('digipharma')->table('pedidos')->get();

        $this->info('Found ' . $oldPedidos->count() . ' pedidos. Migrating...');

        foreach ($oldPedidos as $oldPedido) {
            // Mapeo
            DB::table('inv_pedidos')->insert([
                'id' => $oldPedido->id,
                'numero_pedido' => $oldPedido->numero_pedido,
                'proveedor' => $oldPedido->proveedor ?: '',
                'fecha_pedido' => $oldPedido->fecha_pedido,
                'fecha_esperada' => $oldPedido->fecha_esperada,
                'fecha_recibido' => $oldPedido->fecha_recibido,
                'estado' => $oldPedido->estado,
                'total_articulos' => $oldPedido->total_articulos ?: 0,
                'observaciones' => $oldPedido->observaciones,
                'solicitado_por' => $oldPedido->solicitado_por,
                'recibido_por' => $oldPedido->recibido_por,
                'aprobado_por' => $oldPedido->aprobado_por,
                'cancelado_por' => $oldPedido->cancelado_por,
                'created_at' => $oldPedido->creado_en,
                'updated_at' => $oldPedido->actualizado_en,
            ]);
        }

        $this->info('Fetching old pedidos_detalle...');
        $oldDetalles = DB::connection('digipharma')->table('pedidos_detalle')->get();

        $this->info('Found ' . $oldDetalles->count() . ' detalles. Migrating...');

        foreach ($oldDetalles as $oldDetalle) {
            DB::table('inv_pedido_detalles')->insert([
                'id' => $oldDetalle->id,
                'pedido_id' => $oldDetalle->pedido_id,
                'codigo_producto' => $oldDetalle->codigo_producto,
                'producto_nombre' => $oldDetalle->producto_nombre,
                'producto_tipo' => $oldDetalle->producto_tipo,
                'producto_marca' => $oldDetalle->producto_marca,
                'producto_promedio' => $oldDetalle->producto_promedio,
                'producto_rotacion' => $oldDetalle->producto_rotacion,
                'codigo_sanitario' => $oldDetalle->codigo_sanitario,
                'cum_recibido' => $oldDetalle->cum_recibido,
                'cantidad_solicitada' => $oldDetalle->cantidad_solicitada,
                'cantidad_recibida' => $oldDetalle->cantidad_recibida ?: 0,
                'numero_lote' => $oldDetalle->numero_lote,
                'fecha_vencimiento' => $oldDetalle->fecha_vencimiento,
                'precio_unitario' => $oldDetalle->precio_unitario,
                'estado' => $oldDetalle->estado,
                'aspecto_cumple' => $oldDetalle->aspecto_cumple,
                'embalaje_cumple' => $oldDetalle->embalaje_cumple,
                'cadena_frio_temperatura' => $oldDetalle->cadena_frio_temperatura,
                'contenido_cumple' => $oldDetalle->contenido_cumple,
                'concepto_recepcion' => $oldDetalle->concepto_recepcion,
                'recibido_por' => $oldDetalle->recibido_por,
                'observaciones' => $oldDetalle->observaciones,
                'created_at' => $oldDetalle->creado_en,
                'updated_at' => $oldDetalle->actualizado_en,
            ]);
        }

        $this->info('Migration completed successfully!');
    }
}
