<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega clasificación jerárquica al cargo.
 *
 * Niveles según política de anticipos:
 *   1 = Estratégico/Directivo  (Presidente, VP, Gerente, Directivo, Médico Especialista...)
 *   2 = Táctico I y II         (Coordinador UF, Jefe de área, Analista profesional)
 *   3 = Operativo I y II       (Técnicos, tecnólogos, auxiliares, asistentes)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_cargo', function (Blueprint $table) {
            $table->unsignedTinyInteger('nivel_jerarquico')->default(3)
                  ->comment('1=Estratégico/Directivo, 2=Táctico, 3=Operativo')
                  ->after('nombre_cargo');
            $table->index('nivel_jerarquico');
        });
    }

    public function down(): void
    {
        Schema::table('config_cargo', function (Blueprint $table) {
            $table->dropIndex(['nivel_jerarquico']);
            $table->dropColumn('nivel_jerarquico');
        });
    }
};
