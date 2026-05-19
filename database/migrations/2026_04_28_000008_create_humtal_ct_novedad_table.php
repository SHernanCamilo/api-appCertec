<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_novedad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_cuadro');
            $table->unsignedBigInteger('id_asignacion')->nullable();
            $table->unsignedBigInteger('id_empleado');
            $table->unsignedBigInteger('id_novedad_tipo');
            $table->unsignedBigInteger('id_empleado_reemplaza')->nullable()->comment('para cambios de turno');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->text('motivo')->nullable();
            $table->text('observacion')->nullable();
            $table->unsignedBigInteger('solicitado_por');
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->text('comentario_aprobacion')->nullable();
            $table->timestamps();

            $table->foreign('id_cuadro')
                  ->references('id')
                  ->on('humtal_ct_cuadro')
                  ->restrictOnDelete();

            $table->foreign('id_asignacion')
                  ->references('id')
                  ->on('humtal_ct_asignacion')
                  ->nullOnDelete();

            $table->foreign('id_empleado')
                  ->references('id')
                  ->on('config_person_tercero')
                  ->restrictOnDelete();

            $table->foreign('id_novedad_tipo')
                  ->references('id')
                  ->on('humtal_ct_novedad_tipo')
                  ->restrictOnDelete();

            $table->foreign('id_empleado_reemplaza')
                  ->references('id')
                  ->on('config_person_tercero')
                  ->nullOnDelete();

            $table->foreign('solicitado_por')
                  ->references('id')
                  ->on('users')
                  ->restrictOnDelete();

            $table->foreign('aprobado_por')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_novedad');
    }
};
