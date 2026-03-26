<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de ciudades/destinos clasificadas por tipo para el cálculo de topes de transporte.
 *
 * Tipo A: Bogotá, Medellín, Cali y otras capitales principales
 * Tipo B: Neiva, Pasto, Pereira, Tunja, Yopal, Montería...
 * Tipo C: Pitalito, Duitama, Garzón, Puerto Asís...
 *
 * Esta clasificación determina el valor_tope de transporte interno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anti_ciudades', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('departamento', 100)->nullable();
            $table->enum('tipo_ciudad', ['A', 'B', 'C'])->comment('A=Capital principal, B=Capital intermedia, C=Municipio');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->index('tipo_ciudad');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anti_ciudades');
    }
};
