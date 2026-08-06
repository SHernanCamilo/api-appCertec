<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Tablas transaccionales del módulo Fichas Técnicas.
 *
 * Correcciones de tipado respecto al legacy (donde casi todo era varchar):
 *  - `fecha_ini` / `fecha_fin`          varchar(50) → date
 *  - `fecha_reg` / `fecha_dm` / `fecha_vf` varchar(50) → datetime
 *  - `vlr_contrato`                     varchar(20) con "$" y comas → decimal(16,2)
 *  - `valor` del detalle                varchar(20) → decimal(16,2)
 *  - `user_dm` / `user_vf`              varchar → FK users.id
 *  - `sucursal`                         varchar libre → FK config_ubi_sucursales
 *                                       (se conserva `sucursal_legacy` para trazabilidad)
 *
 * Añadidos:
 *  - `id_empresa` denormalizado: el legacy lo obtenía con un JOIN a usuarios en
 *    cada consulta de listado. Guardarlo evita ese JOIN y permite indexarlo.
 *  - `total_detalles` / `valor_total_detalles`: contadores mantenidos por trigger.
 *  - `fich_historial_estados`: bitácora del workflow, alimentada por trigger.
 *  - `softDeletes`: el legacy nunca borraba (estado 7). Se mantiene ese estado
 *    como semántica de negocio y además se habilita borrado lógico técnico.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // Ficha técnica  (legacy: ficha)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_fichas', function (Blueprint $table): void {
            $table->id();

            $table->string('consecutivo', 60)->nullable()
                ->comment('Se asigna al aprobar. Formato PREFIJO-AAAA-N (o -N por versión OS)');
            $table->unsignedBigInteger('id_padre')->nullable()
                ->comment('Ficha original cuando este registro es una actualización (OS)');
            $table->unsignedSmallInteger('version')->default(1)
                ->comment('1 = ficha original, >1 = número de actualización');

            // Ubicación / contexto organizacional
            $table->unsignedBigInteger('id_empresa')->nullable();
            $table->unsignedBigInteger('id_sucursal')->nullable();
            $table->string('sucursal_legacy', 100)->nullable()
                ->comment('Nombre textual de sucursal del sistema JADE (NEIVA, CENTRO, TUNJA...)');

            // Datos del contrato
            $table->unsignedBigInteger('id_agremiacion');
            $table->unsignedBigInteger('id_objeto_contrato');
            $table->unsignedBigInteger('id_especialidad');
            $table->decimal('vlr_contrato', 16, 2)->default(0);
            $table->date('fecha_ini');
            $table->date('fecha_fin');

            // Workflow
            $table->unsignedBigInteger('id_estado');
            $table->unsignedBigInteger('id_user_reg')
                ->comment('Generador que creó la ficha');
            $table->dateTime('fecha_reg')->nullable();

            // Autorización (Dirección Médica) — legacy *_dm
            $table->unsignedBigInteger('user_autoriza_id')->nullable();
            $table->dateTime('fecha_autoriza')->nullable();
            $table->text('obs_autoriza')->nullable();

            // Aprobación (Vicepresidencia Financiera) — legacy *_vf
            $table->unsignedBigInteger('user_aprueba_id')->nullable();
            $table->dateTime('fecha_aprueba')->nullable();
            $table->text('obs_aprueba')->nullable();

            // Actualizaciones (OS)
            $table->string('obs_os', 500)->nullable()
                ->comment('Descripción del cambio cuando la ficha es una actualización');
            $table->string('novedad', 500)->nullable();

            // Contadores denormalizados (mantenidos por trigger)
            $table->unsignedInteger('total_detalles')->default(0);
            $table->decimal('valor_total_detalles', 18, 2)->default(0);
            $table->unsignedInteger('total_profesionales')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('id_padre', 'fk_ffic_padre')
                ->references('id')->on('fich_fichas')->nullOnDelete();
            $table->foreign('id_empresa', 'fk_ffic_empresa')
                ->references('id')->on('ent_empresas')->nullOnDelete();
            $table->foreign('id_sucursal', 'fk_ffic_sucursal')
                ->references('id')->on('config_ubi_sucursales')->nullOnDelete();
            $table->foreign('id_agremiacion', 'fk_ffic_agremiacion')
                ->references('id')->on('fich_agremiaciones')->restrictOnDelete();
            $table->foreign('id_objeto_contrato', 'fk_ffic_objeto')
                ->references('id')->on('fich_objetos_contrato')->restrictOnDelete();
            $table->foreign('id_especialidad', 'fk_ffic_especialidad')
                ->references('id')->on('fich_especialidades')->restrictOnDelete();
            $table->foreign('id_estado', 'fk_ffic_estado')
                ->references('id')->on('fich_estados')->restrictOnDelete();
            $table->foreign('id_user_reg', 'fk_ffic_user_reg')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('user_autoriza_id', 'fk_ffic_user_autoriza')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_aprueba_id', 'fk_ffic_user_aprueba')
                ->references('id')->on('users')->nullOnDelete();

            // Índices dirigidos a las consultas reales del sistema
            $table->index('consecutivo');
            $table->index(['id_estado', 'id_sucursal'], 'idx_ffic_estado_sucursal');
            $table->index(['id_user_reg', 'id_estado'], 'idx_ffic_generador_estado');
            $table->index(['id_estado', 'fecha_fin'], 'idx_ffic_estado_vigencia');
            $table->index(['id_agremiacion', 'id_especialidad'], 'idx_ffic_agrem_esp');
            $table->index('id_padre');
            $table->index('fecha_fin');
        });

        // ─────────────────────────────────────────────────────────────────
        // Detalle de servicios  (legacy: detalles_ficha)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_detalles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_ficha');

            $table->string('tipo_liquidacion', 100)->nullable()
                ->comment('Ej: PAQUETE, EVENTO, GRUPO QUIRÚRGICO');
            $table->string('tipo_servicio', 150)->nullable()
                ->comment('Texto libre en el legacy; se conserva junto al FK normalizado');
            $table->unsignedBigInteger('id_tipo_servicio')->nullable();

            $table->string('cups', 10)->nullable();
            $table->string('grupo', 3)->nullable();
            $table->string('subgrupo', 4)->nullable();
            $table->string('forma_pago', 100)->nullable()
                ->comment('MONTO FIJO, PRODUCCIÓN, MENSUAL...');
            $table->string('homologo', 60)->nullable()
                ->comment('code_manual homologado (FK lógica a fich_homologos.code_manual)');
            $table->string('variacion', 10)->nullable()
                ->comment('Porcentaje de liquidación aplicado');
            $table->decimal('valor', 16, 2)->default(0);
            $table->unsignedBigInteger('id_obs_item')->nullable();
            $table->string('novedad', 100)->nullable()
                ->comment('AGREGADO / MODIFICADO / ELIMINADO en actualizaciones OS');
            $table->timestamps();

            $table->foreign('id_ficha', 'fk_fdet_ficha')
                ->references('id')->on('fich_fichas')->cascadeOnDelete();
            $table->foreign('id_tipo_servicio', 'fk_fdet_tipo_servicio')
                ->references('id')->on('fich_tipos_servicio')->nullOnDelete();
            $table->foreign('id_obs_item', 'fk_fdet_obs_item')
                ->references('id')->on('fich_obs_items')->nullOnDelete();

            $table->index('id_ficha');
            $table->index('cups');
            $table->index('homologo');
            $table->index(['id_ficha', 'cups'], 'idx_fdet_ficha_cups');
        });

        // ─────────────────────────────────────────────────────────────────
        // Ficha ↔ Profesional  (legacy: inter_ficha_prof)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_ficha_profesional', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_ficha');
            $table->unsignedBigInteger('id_profesional');
            $table->string('novedad', 50)->nullable();
            $table->timestamps();

            $table->foreign('id_ficha', 'fk_ffp_ficha')
                ->references('id')->on('fich_fichas')->cascadeOnDelete();
            $table->foreign('id_profesional', 'fk_ffp_profesional')
                ->references('id')->on('fich_profesionales')->restrictOnDelete();

            $table->unique(['id_ficha', 'id_profesional'], 'uq_ffp_ficha_profesional');
            $table->index('id_profesional');
        });

        // ─────────────────────────────────────────────────────────────────
        // Observaciones de la ficha  (legacy: observaciones)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_observaciones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_ficha');
            $table->string('desc_obs', 500);
            $table->unsignedBigInteger('usuario_crea_id')->nullable();
            $table->timestamps();

            $table->foreign('id_ficha', 'fk_fobsv_ficha')
                ->references('id')->on('fich_fichas')->cascadeOnDelete();
            $table->foreign('usuario_crea_id', 'fk_fobsv_usuario')
                ->references('id')->on('users')->nullOnDelete();

            $table->index('id_ficha');
        });

        // ─────────────────────────────────────────────────────────────────
        // Comentarios de validación  (legacy: comentarios)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_comentarios', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_ficha');
            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_estado')->nullable()
                ->comment('Estado resultante al momento del comentario');
            $table->longText('descripcion');
            $table->timestamps();

            $table->foreign('id_ficha', 'fk_fcom_ficha')
                ->references('id')->on('fich_fichas')->cascadeOnDelete();
            $table->foreign('id_usuario', 'fk_fcom_usuario')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('id_estado', 'fk_fcom_estado')
                ->references('id')->on('fich_estados')->nullOnDelete();

            $table->index(['id_ficha', 'created_at'], 'idx_fcom_ficha_fecha');
        });

        // ─────────────────────────────────────────────────────────────────
        // Historial de estados — NUEVO (no existía en el legacy).
        // Se alimenta por trigger en cada cambio de id_estado.
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_historial_estados', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_ficha');
            $table->unsignedBigInteger('id_estado_anterior')->nullable();
            $table->unsignedBigInteger('id_estado_nuevo');
            $table->unsignedBigInteger('id_usuario')->nullable();
            $table->text('observacion')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('id_ficha', 'fk_fhis_ficha')
                ->references('id')->on('fich_fichas')->cascadeOnDelete();

            $table->index(['id_ficha', 'created_at'], 'idx_fhis_ficha_fecha');
            $table->index('id_estado_nuevo');
        });

        // Restricción de integridad de negocio: la vigencia debe ser coherente.
        // El legacy solo validaba esto en JavaScript.
        DB::statement(
            'ALTER TABLE fich_fichas
             ADD CONSTRAINT chk_ffic_vigencia CHECK (fecha_fin >= fecha_ini)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fich_historial_estados');
        Schema::dropIfExists('fich_comentarios');
        Schema::dropIfExists('fich_observaciones');
        Schema::dropIfExists('fich_ficha_profesional');
        Schema::dropIfExists('fich_detalles');
        Schema::dropIfExists('fich_fichas');
    }
};
