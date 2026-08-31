<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega sucursal_id a las órdenes de compra y pedidos de inventario.
 *
 * Motivo: las secuencias documentales son por sucursal (config_sec_* ámbito
 * 'sucursal'). Hasta ahora una OC podía nacer con el prefijo de otra sucursal
 * (ej. una compra de Neiva quedaba con consecutivo de Florencia) porque no se
 * registraba a qué sucursal pertenece. Este campo deja trazada la sucursal
 * elegida al sincronizar/crear.
 *
 * Es nullable para no romper los registros históricos ya existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inv_ordenes_compra') && !Schema::hasColumn('inv_ordenes_compra', 'sucursal_id')) {
            Schema::table('inv_ordenes_compra', function (Blueprint $table) {
                $table->unsignedBigInteger('sucursal_id')->nullable()->after('oc_indigo')
                    ->comment('Sucursal (config_ubi_sucursales) a la que pertenece la OC');
                $table->index('sucursal_id');
            });
        }

        if (Schema::hasTable('inv_pedidos') && !Schema::hasColumn('inv_pedidos', 'sucursal_id')) {
            Schema::table('inv_pedidos', function (Blueprint $table) {
                $table->unsignedBigInteger('sucursal_id')->nullable()
                    ->comment('Sucursal (config_ubi_sucursales) a la que pertenece el pedido');
                $table->index('sucursal_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inv_ordenes_compra') && Schema::hasColumn('inv_ordenes_compra', 'sucursal_id')) {
            Schema::table('inv_ordenes_compra', function (Blueprint $table) {
                $table->dropIndex(['sucursal_id']);
                $table->dropColumn('sucursal_id');
            });
        }

        if (Schema::hasTable('inv_pedidos') && Schema::hasColumn('inv_pedidos', 'sucursal_id')) {
            Schema::table('inv_pedidos', function (Blueprint $table) {
                $table->dropIndex(['sucursal_id']);
                $table->dropColumn('sucursal_id');
            });
        }
    }
};
