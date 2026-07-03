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
        Schema::create('inv_pedido_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('inv_pedidos')->onDelete('cascade');
            $table->string('codigo_producto', 100);
            $table->string('producto_nombre', 200);
            $table->string('producto_tipo', 100)->nullable();
            $table->string('producto_marca', 100)->nullable();
            $table->string('producto_promedio', 50)->nullable();
            $table->string('producto_rotacion', 20)->nullable();
            $table->string('codigo_sanitario', 100)->nullable();
            $table->string('cum_recibido', 50)->nullable();
            $table->integer('cantidad_solicitada');
            $table->integer('cantidad_recibida')->default(0);
            $table->string('numero_lote', 50)->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->decimal('precio_unitario', 10, 2)->nullable();
            $table->enum('estado', ['pendiente', 'en_transito', 'parcial', 'completo', 'recibido', 'rechazado'])->default('pendiente');
            $table->boolean('aspecto_cumple')->nullable();
            $table->boolean('embalaje_cumple')->nullable();
            $table->decimal('cadena_frio_temperatura', 5, 2)->nullable();
            $table->boolean('contenido_cumple')->nullable();
            $table->enum('concepto_recepcion', ['aceptado', 'rechazado'])->nullable();
            $table->unsignedBigInteger('recibido_por')->nullable();
            $table->text('observaciones')->nullable();
            
            $table->timestamps();
            
            $table->index('codigo_producto');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_pedido_detalles');
    }
};
