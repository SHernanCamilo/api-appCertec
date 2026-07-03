<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inv_productos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 255);
            $table->string('tipo_producto', 100)->nullable();
            $table->string('codigo_agrupador', 50)->nullable();
            $table->string('agrupador', 255)->nullable();
            $table->string('fabricante', 255)->nullable();
            $table->string('unidad_empaque', 100)->nullable();
            $table->decimal('costo_promedio', 14, 2)->nullable();
            $table->decimal('ultimo_costo', 14, 2)->nullable();
            $table->decimal('precio_venta', 14, 2)->nullable();
            $table->string('estado', 50)->default('ACTIVO');
            $table->string('tipo_riesgo', 100)->nullable();
            $table->string('concentracion', 255)->nullable();
            $table->string('registro_sanitario', 100)->nullable();
            $table->string('presentacion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('codigo_agrupador');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_productos');
    }
};
