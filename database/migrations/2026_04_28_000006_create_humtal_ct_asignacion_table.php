<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_asignacion', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_cuadro');
            $table->unsignedBigInteger('id_empleado');
            $table->date('fecha');
            $table->unsignedBigInteger('id_plantilla')->nullable()->comment('null = descanso');
            $table->boolean('es_descanso')->default(false);
            $table->boolean('es_festivo')->default(false);
            $table->time('hora_inicio_override')->nullable()->comment('sobreescribe plantilla si se necesita');
            $table->time('hora_fin_override')->nullable();
            $table->string('observacion', 255)->nullable();
            $table->timestamps();

            $table->foreign('id_cuadro')
                  ->references('id')
                  ->on('humtal_ct_cuadro')
                  ->cascadeOnDelete();

            $table->foreign('id_empleado')
                  ->references('id')
                  ->on('config_person_tercero')
                  ->restrictOnDelete();

            $table->foreign('id_plantilla')
                  ->references('id')
                  ->on('humtal_ct_plantillas')
                  ->nullOnDelete();

            $table->unique(['id_cuadro', 'id_empleado', 'fecha']);

            // Índice para validar solapamiento
            $table->index(['id_empleado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_asignacion');
    }
};
