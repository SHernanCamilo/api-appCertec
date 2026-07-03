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
        Schema::create('inv_orden_compra_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('inv_ordenes_compra')->onDelete('cascade');
            $table->foreignId('pedido_detalle_id')->constrained('inv_pedido_detalles')->onDelete('cascade');
            $table->string('clasificacion_venta', 100)->nullable();
            $table->string('proveedor', 200);
            $table->integer('cantidad_solicitada_compra');
            $table->date('fecha_entrega_estimada')->nullable();
            $table->string('clasificacion_vie', 100)->nullable();
            $table->decimal('precio_unitario_compra', 10, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->enum('estado', ['pendiente', 'en_transito', 'confirmado', 'recibida', 'cancelada'])->default('pendiente');
            
            $table->timestamps();
            
            $table->index('proveedor');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_orden_compra_detalles');
    }
};
