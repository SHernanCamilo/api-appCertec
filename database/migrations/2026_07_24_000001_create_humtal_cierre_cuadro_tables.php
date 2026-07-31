<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas para el m+¦dulo de Cierre de Cuadro de Turnos.
 *
 * humtal_parametro_cierre_cuadro: configuraci+¦n del cierre (autom+ítico/manual, d+¡a, hora).
 * humtal_bloqueo_cuadro: registro de cuadros/unidades bloqueadas con auditor+¡a.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Configuraci+¦n del cierre
        Schema::create('humtal_parametro_cierre_cuadro', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_bloqueo', ['automatico', 'manual'])->default('automatico');
            $table->enum('tipo_nomina', ['mensual', 'quincenal'])->default('mensual');
            $table->unsignedSmallInteger('dia_cierre')->default(22)->comment('D+¡a del mes en que se cierra (1-31)');
            $table->time('hora_cierre')->default('23:59')->comment('Hora exacta de cierre');
            $table->boolean('aplica_mes_actual')->default(true)->comment('true=cierra el mes en curso, false=cierra el mes anterior');
            $table->unsignedBigInteger('id_empresa')->nullable()->comment('Si null, aplica a todas');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['id_empresa', 'activo']);
        });

        // Registro de bloqueos (auditor+¡a)
        Schema::create('humtal_bloqueo_cuadro', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_cuadro')->nullable();
            $table->unsignedBigInteger('id_unidad_funcional');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->enum('estado', ['bloqueado', 'desbloqueado'])->default('bloqueado');

            // Bloqueo
            $table->datetime('bloqueado_en');
            $table->unsignedBigInteger('bloqueado_por')->nullable()->comment('null = autom+ítico');
            $table->enum('tipo_bloqueo', ['automatico', 'manual'])->default('manual');

            // Desbloqueo
            $table->datetime('desbloqueado_en')->nullable();
            $table->unsignedBigInteger('desbloqueado_por')->nullable();
            $table->string('motivo_desbloqueo', 255)->nullable();

            $table->timestamps();

            $table->foreign('id_cuadro')->references('id')->on('humtal_ct_cuadro')->nullOnDelete();
            $table->foreign('id_unidad_funcional')->references('id')->on('config_unidades_funcionales')->restrictOnDelete();

            $table->index(['id_unidad_funcional', 'anio', 'mes', 'estado'], 'idx_bloqueo_unidad_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_bloqueo_cuadro');
        Schema::dropIfExists('humtal_parametro_cierre_cuadro');
    }
};
