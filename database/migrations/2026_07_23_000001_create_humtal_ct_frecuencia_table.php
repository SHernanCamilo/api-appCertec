<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_frecuencia', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_empleado');
            $table->unsignedBigInteger('id_plantilla');
            $table->unsignedBigInteger('id_cuadro')->nullable();

            $table->enum('tipo_frecuencia', [
                'sin_programacion',
                'por_numero_dias',
                'por_dias_semana',
                'dias_del_mes',
            ]);

            $table->unsignedSmallInteger('cada_n_dias')->nullable();
            $table->json('dias_semana')->nullable();
            $table->json('dias_mes')->nullable();

            $table->date('fecha_inicio');
            $table->date('fecha_fin');

            $table->boolean('incluir_festivos')->default(false);
            $table->boolean('incluir_dominicales')->default(false);
            $table->boolean('es_descanso')->default(false);

            $table->time('hora_inicio_override')->nullable();
            $table->time('hora_fin_override')->nullable();

            $table->string('observacion', 255)->nullable();
            $table->boolean('estado')->default(true);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();

            $table->foreign('id_empleado')->references('id')->on('config_person_tercero')->restrictOnDelete();
            $table->foreign('id_plantilla')->references('id')->on('humtal_ct_plantillas')->restrictOnDelete();
            $table->foreign('id_cuadro')->references('id')->on('humtal_ct_cuadro')->nullOnDelete();

            $table->index(['id_empleado', 'estado']);
            $table->index(['fecha_inicio', 'fecha_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_frecuencia');
    }
};
