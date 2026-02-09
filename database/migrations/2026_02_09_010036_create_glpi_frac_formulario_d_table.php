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
        Schema::create('glpiFrac_formulario_d', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_formulario_c')->comment('FK a glpiFrac_formulario_c');
            $table->string('titulo_ticket', 255)->nullable()->comment('Título del ticket');
            $table->text('desc_ticket')->nullable()->comment('Descripción del ticket');
            $table->timestamp('fecha_ticket')->nullable()->comment('Fecha del ticket');
            $table->integer('id_fiel')->nullable()->comment('ID del campo/field');
            $table->string('nombre_usuario_Ticket', 255)->nullable()->comment('Nombre del usuario del ticket');
            $table->boolean('enviado_fracttal')->default(false)->comment('Si fue enviado a Fracttal');
            $table->string('estado', 50)->nullable()->comment('Estado del formulario');
            $table->string('solicitud_f_id', 100)->nullable()->comment('ID de solicitud en Fracttal');
            $table->timestamps();
            
            $table->foreign('id_formulario_c')->references('id')->on('glpiFrac_formulario_c')->onDelete('cascade');
            
            $table->index('id_formulario_c');
            $table->index('titulo_ticket');
            $table->index('fecha_ticket');
            $table->index('enviado_fracttal');
            $table->index('estado');
            $table->index('solicitud_f_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('glpiFrac_formulario_d');
    }
};
