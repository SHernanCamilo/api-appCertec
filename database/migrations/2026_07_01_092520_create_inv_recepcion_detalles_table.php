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
        Schema::create('inv_recepcion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_id')->constrained('inv_recepciones')->onDelete('cascade');
            $table->foreignId('pedido_detalle_id')->constrained('inv_pedido_detalles');
            $table->string('codigo_producto', 50)->nullable();
            $table->string('producto_nombre', 255)->nullable();
            $table->decimal('cantidad_solicitada', 10, 2)->default(0);
            $table->decimal('cantidad_recibida', 10, 2)->default(0);
            $table->string('numero_lote', 100)->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('codigo_sanitario', 100)->nullable();
            $table->boolean('aspecto_cumple')->nullable();
            $table->boolean('embalaje_cumple')->nullable();
            $table->boolean('contenido_cumple')->nullable();
            $table->decimal('cadena_frio_temperatura', 5, 2)->nullable();
            $table->string('concepto_recepcion', 20)->nullable();
            
            $table->boolean('es_medicamento_vital')->default(false);
            $table->string('mvd_ium', 50)->nullable();
            $table->string('mvd_solicitante', 255)->nullable();
            $table->string('mvd_principio_activo', 255)->nullable();
            $table->string('mvd_forma_farmaceutica', 150)->nullable();
            $table->string('mvd_presentacion_comercial', 255)->nullable();
            $table->date('mvd_fecha_autorizacion')->nullable();
            $table->text('observaciones_recepcion')->nullable();
            
            $table->timestamps();
            
            $table->index('codigo_producto');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_recepcion_detalles');
    }
};
