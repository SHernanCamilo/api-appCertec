<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para JORNADA PARTIDA (segundo rango horario opcional).
 *
 * Caso de uso: personal administrativo con horario partido.
 * Ejemplo: 7:00-12:00 + 14:00-18:00 = 9 horas con descanso de almuerzo.
 *
 * - hora_inicio / hora_fin: rango principal (obligatorio)
 * - hora_inicio_2 / hora_fin_2: segundo rango (opcional)
 * - duracion_horas: SUMA de ambos rangos
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plantillas: agregar segundo rango horario opcional
        Schema::table('humtal_ct_plantillas', function (Blueprint $table) {
            if (!Schema::hasColumn('humtal_ct_plantillas', 'hora_inicio_2')) {
                $table->time('hora_inicio_2')->nullable()->after('hora_fin');
            }
            if (!Schema::hasColumn('humtal_ct_plantillas', 'hora_fin_2')) {
                $table->time('hora_fin_2')->nullable()->after('hora_inicio_2');
            }
        });

        // Asignaciones: overrides para el segundo rango
        Schema::table('humtal_ct_asignacion', function (Blueprint $table) {
            if (!Schema::hasColumn('humtal_ct_asignacion', 'hora_inicio_override_2')) {
                $table->time('hora_inicio_override_2')->nullable()->after('hora_fin_override');
            }
            if (!Schema::hasColumn('humtal_ct_asignacion', 'hora_fin_override_2')) {
                $table->time('hora_fin_override_2')->nullable()->after('hora_inicio_override_2');
            }
        });
    }

    public function down(): void
    {
        Schema::table('humtal_ct_plantillas', function (Blueprint $table) {
            $table->dropColumn(['hora_inicio_2', 'hora_fin_2']);
        });

        Schema::table('humtal_ct_asignacion', function (Blueprint $table) {
            $table->dropColumn(['hora_inicio_override_2', 'hora_fin_override_2']);
        });
    }
};
