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
        Schema::create('seg_permisos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_modulo');
            $table->string('nombre', 100);
            $table->string('codigo', 50)->unique();
            $table->text('descripcion')->nullable();
            $table->enum('tipo', ['boton', 'accion', 'menu'])->default('boton');
            $table->string('icono', 50)->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('estado')->default(true);
            $table->timestamps();

            // Foreign key
            $table->foreign('id_modulo')->references('id')->on('seg_modulos')->onDelete('cascade');
            
            // Índices
            $table->index('id_modulo');
            $table->index('codigo');
            $table->index('tipo');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seg_permisos');
    }
};
