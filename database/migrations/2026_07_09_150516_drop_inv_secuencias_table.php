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
        Schema::dropIfExists('inv_secuencias');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('inv_secuencias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_documento', 50);
            $table->integer('formulario_id')->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('prefijo', 10)->nullable();
            $table->string('sufijo', 10)->nullable();
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->integer('anio_actual')->nullable();
            $table->integer('longitud')->default(4);
            $table->unsignedBigInteger('usuario_creacion')->nullable();
            $table->timestamps();
        });
    }
};
