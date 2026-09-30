<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_from_tras_asistencial')) {
            return;
        }

        Schema::create('bi_from_tras_asistencial', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['primario', 'secundario'])
                ->comment('Diferencia hoja primaria o secundaria');
            $table->string('formato', 30)->default('primario')
                ->comment('Variante UI: primario, primarioCompleto, secundario, secundarioCompleto');
            $table->enum('estado', ['guardado', 'confirmado'])->default('guardado');
            $table->dateTime('fecha_guarda');
            $table->foreignId('usuario_guarda_id')->constrained('users');
            $table->dateTime('fecha_confirma')->nullable();
            $table->foreignId('usuario_confirma_id')->nullable()->constrained('users');
            $table->date('fecha_atencion')->nullable();
            $table->string('nombres_apellidos', 255)->nullable();
            $table->string('tipo_identificacion', 20)->nullable();
            $table->string('numero_identificacion', 30)->nullable();
            $table->string('estado_paciente', 10)->nullable();
            $table->json('datos')->comment('Payload completo del formulario');
            $table->timestamps();

            $table->index(['tipo', 'estado']);
            $table->index('numero_identificacion');
            $table->index('fecha_guarda');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_from_tras_asistencial');
    }
};
