<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festivos para el cálculo de horas festivas en el cuadro de turnos.
 *
 * Cada festivo se identifica por fecha exacta (YYYY-MM-DD).
 * Adicionalmente, los DOMINGOS siempre se consideran festivos por defecto
 * en la lógica de cálculo (no requieren registro en esta tabla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_festivos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha')->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->index('fecha');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_festivos');
    }
};
