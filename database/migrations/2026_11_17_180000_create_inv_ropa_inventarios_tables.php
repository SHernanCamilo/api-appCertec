<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteo físico de ropa hospitalaria.
 *
 * El borrador (estado 2) es la toma en pantalla. Al guardar se copia a otra
 * cabecera cerrada (estado 1) con otro consecutivo. Un cerrado no se edita
 * ni se anula. Los reportes solo leen el estado 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_ropa_inventarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('consecutivo')->unique();
            $table->unsignedTinyInteger('estado')->default(2)->comment('2 borrador, 1 cerrado');
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('id_sucursal')->nullable()->constrained('config_ubi_sucursales');
            $table->foreignId('id_sede')->nullable()->constrained('config_ubi_sede');
            $table->foreignId('id_unidad_funcional')->nullable()->constrained('config_unidades_funcionales');
            $table->char('periodo', 7)->nullable()->comment('YYYY-MM');
            $table->date('fecha_corte')->nullable();
            $table->string('comentario', 255)->nullable();
            $table->dateTime('fecha_registro')->nullable();
            $table->foreignId('id_borrador')->nullable()->unique()->constrained('inv_ropa_inventarios');
            $table->timestamps();

            $table->index(['id_unidad_funcional', 'periodo', 'estado'], 'inv_ropa_inv_unidad_periodo');
            $table->index('estado');
        });

        Schema::create('inv_ropa_inventario_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_inventario')->constrained('inv_ropa_inventarios')->cascadeOnDelete();
            $table->foreignId('id_prenda')->constrained('inv_ropa_prendas');
            $table->unsignedInteger('camas');
            $table->unsignedInteger('cama_extra')->default(0);
            $table->decimal('constante', 8, 2);
            $table->unsignedInteger('cantidad');
            $table->unsignedTinyInteger('estado')->default(1)->comment('1 en el conteo, 0 quitada del borrador');
            $table->timestamps();

            $table->index(['id_inventario', 'id_prenda', 'estado'], 'inv_ropa_linea_prenda');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_ropa_inventario_lineas');
        Schema::dropIfExists('inv_ropa_inventarios');
    }
};
