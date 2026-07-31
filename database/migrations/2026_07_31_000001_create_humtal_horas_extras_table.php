<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_horas_extras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_empleado');
            $table->unsignedBigInteger('id_cuadro')->nullable();
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('motivo', 255)->nullable();
            $table->enum('tipo', ['hora_extra', 'evento'])->default('hora_extra');
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('id_empleado')->references('id')->on('config_person_tercero')->restrictOnDelete();
            $table->foreign('id_cuadro')->references('id')->on('humtal_ct_cuadro')->nullOnDelete();
            $table->index(['id_empleado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_horas_extras');
    }
};
