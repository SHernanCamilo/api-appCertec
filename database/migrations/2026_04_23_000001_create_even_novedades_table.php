<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: Catálogo de Novedades para Eventos
 *
 * Crea la tabla:
 *   event_novedades  → catálogo maestro de tipos de novedad
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_novedades', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 20)->unique()
                ->comment('Código único de la novedad, ej: INC, VAC, PER');

            $table->string('descripcion', 150)
                ->comment('Nombre descriptivo de la novedad');

            $table->boolean('cubre')->default(0)
                ->comment('Indica si la novedad cubre (1) o no (0) el período');

            $table->boolean('activo')->default(1)
                ->comment('1 = activo, 0 = inactivo');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_novedades');
    }
};
