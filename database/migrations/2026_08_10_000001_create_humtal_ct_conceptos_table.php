<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de conceptos del cuadro de turnos.
 * Cada concepto tiene una fórmula que define cómo se calcula su valor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_conceptos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre', 100);
            $table->enum('tipo_concepto', ['devengado', 'deducido']);
            $table->text('formula')->comment('Fórmula con variables entre corchetes: [Horas Nocturnas] * [Valor Hora] * 0.35');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_conceptos');
    }
};
