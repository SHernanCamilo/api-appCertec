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
        Schema::create('inv_pedidos', function (Blueprint $table) {
            $table->id();
            $table->string('numero_pedido', 50)->unique();
            $table->string('proveedor', 200);
            $table->date('fecha_pedido');
            $table->date('fecha_esperada')->nullable();
            $table->dateTime('fecha_recibido')->nullable();
            $table->enum('estado', ['pendiente', 'en_proceso', 'recibido', 'aprobado', 'rechazado', 'cancelado'])->default('pendiente');
            $table->integer('total_articulos')->default(0);
            $table->text('observaciones')->nullable();
            
            $table->unsignedBigInteger('solicitado_por');
            $table->unsignedBigInteger('recibido_por')->nullable();
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->unsignedBigInteger('cancelado_por')->nullable();
            
            $table->timestamps();
            
            $table->index('estado');
            $table->index('fecha_pedido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_pedidos');
    }
};
