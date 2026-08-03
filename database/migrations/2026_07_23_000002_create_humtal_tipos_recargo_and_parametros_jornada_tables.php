<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de parametrizaci+�n de recargos y jornada laboral.
 *
 * humtal_tipos_recargo: cat+�logo de tipos de recargo/hora extra con porcentaje.
 * humtal_parametros_jornada: franjas horarias + topes de jornada, versionados por vigencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tipos de recargo (RN, HED, HEN, RDF, HEDF, HENF, etc.)
        if (!Schema::hasTable('humtal_tipos_recargo')) {
            Schema::create('humtal_tipos_recargo', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 10)->unique();
                $table->string('nombre', 100);
                $table->decimal('porcentaje', 5, 2)->comment('Porcentaje de recargo: 25, 35, 75, 100, 150');
                $table->boolean('es_hora_extra')->default(false)->comment('true = hora extra, false = recargo simple');
                $table->boolean('aplica_dominical_festivo')->default(false);
                $table->time('hora_inicio')->nullable()->comment('Inicio de franja (ej: 21:00 para nocturno)');
                $table->time('hora_fin')->nullable()->comment('Fin de franja (ej: 06:00)');
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        // Parámetros de jornada (topes + franjas diurna/nocturna, versionados)
        if (!Schema::hasTable('humtal_parametros_jornada')) {
            Schema::create('humtal_parametros_jornada', function (Blueprint $table) {
                $table->id();
                $table->decimal('horas_max_dia', 4, 2)->default(8.00);
                $table->decimal('horas_max_semana', 5, 2)->default(46.00);
                $table->decimal('horas_max_mes', 6, 2)->nullable()->comment('Calculado: horas_max_semana * 4.33');
                $table->time('jornada_diurna_inicio')->default('06:00');
                $table->time('jornada_diurna_fin')->default('21:00');
                $table->time('jornada_nocturna_inicio')->default('21:00');
                $table->time('jornada_nocturna_fin')->default('06:00');
                $table->date('vigente_desde');
                $table->date('vigente_hasta')->nullable()->comment('null = vigente actual');
                $table->boolean('activo')->default(true);
                $table->text('observacion')->nullable()->comment('Ej: Ley 2101 de 2021 - reduccion progresiva');
                $table->timestamps();

                $table->index(['vigente_desde', 'vigente_hasta', 'activo'], 'idx_param_jornada_vigencia');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_parametros_jornada');
        Schema::dropIfExists('humtal_tipos_recargo');
    }
};
