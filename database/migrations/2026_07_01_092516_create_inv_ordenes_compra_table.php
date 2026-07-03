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
        Schema::create('inv_ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->string('numero_orden_compra', 100)->unique();
            $table->date('fecha_orden');
            $table->text('observaciones')->nullable();
            $table->enum('estado', ['pendiente', 'en_transito', 'confirmado', 'recibida', 'cancelada', 'en_sitio'])->default('pendiente');
            $table->integer('sincronizado_indigo')->default(0);
            $table->unsignedBigInteger('creado_por');
            $table->string('oc_indigo', 100)->nullable();
            
            $table->timestamps();
            
            $table->index('fecha_orden');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_ordenes_compra');
    }
};
