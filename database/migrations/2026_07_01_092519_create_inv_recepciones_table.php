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
        Schema::create('inv_recepciones', function (Blueprint $table) {
            $table->id();
            $table->string('numero_recepcion', 50)->nullable();
            $table->foreignId('compra_id')->constrained('inv_ordenes_compra');
            $table->string('numero_orden_compra', 50)->nullable();
            $table->string('oc_indigo', 50)->nullable();
            $table->dateTime('fecha_recepcion');
            $table->unsignedBigInteger('recibido_por');
            $table->integer('total_items')->default(0);
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('completa');
            
            $table->timestamps();
            
            $table->index('numero_recepcion');
            $table->index('fecha_recepcion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_recepciones');
    }
};
