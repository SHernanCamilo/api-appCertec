<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega nivel_jerarquico a anti_reglas para que cada regla
 * tenga un tope diferente según el nivel del empleado.
 *
 * Ejemplo:
 *   concepto: Viáticos Nacional Alimentación
 *   regla nivel 1 → valor_tope: 125000
 *   regla nivel 2 → valor_tope: 110000
 *   regla nivel 3 → valor_tope: 110000
 *
 * El campo descripcion pasa a ser el sub-ítem (Desayuno, Almuerzo, Cena, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('anti_reglas', 'nivel_jerarquico')) {
            Schema::table('anti_reglas', function (Blueprint $table) {
                $table->unsignedTinyInteger('nivel_jerarquico')->default(0)
                      ->comment('0=aplica a todos, 1=Estratégico, 2=Táctico, 3=Operativo')
                      ->after('id_concepto');
                $table->index(['id_concepto', 'nivel_jerarquico']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('anti_reglas', function (Blueprint $table) {
            $table->dropIndex(['id_concepto', 'nivel_jerarquico']);
            $table->dropColumn('nivel_jerarquico');
        });
    }
};
