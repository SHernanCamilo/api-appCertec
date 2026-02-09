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
        Schema::create('glpiFrac_respuesta_fracttals', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_respuesta', 100)->nullable()->comment('Tipo de respuesta de Fracttal');
            $table->unsignedBigInteger('id_formulario_c')->nullable()->comment('FK a glpiFrac_formulario_c');
            $table->string('solicitud_f_id', 100)->nullable()->comment('ID de solicitud en Fracttal');
            $table->integer('id_status_solicitud')->nullable()->comment('ID del estado de la solicitud');
            $table->timestamp('fecha_Solicitud')->nullable()->comment('Fecha de la solicitud');
            $table->timestamps();
            
            $table->foreign('id_formulario_c')->references('id')->on('glpiFrac_formulario_c')->onDelete('set null');
            
            $table->index('tipo_respuesta');
            $table->index('id_formulario_c');
            $table->index('solicitud_f_id');
            $table->index('id_status_solicitud');
            $table->index('fecha_Solicitud');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('glpiFrac_respuesta_fracttals');
    }
};
