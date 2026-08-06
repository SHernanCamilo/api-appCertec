<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Fichas Técnicas Médicas (migración JADE legacy).
 *
 * Catálogos maestros del módulo. Todas las tablas llevan prefijo `fich_`.
 *
 * Mejoras frente al legacy:
 *  - `estado` pasa de int(11) a boolean (semántica real 1/0).
 *  - `nit` / `doc` pasan de int(11) —que desborda con NITs de 10 dígitos— a string.
 *  - Se agregan `timestamps` para auditoría (el legacy no tenía).
 *  - Se agregan claves únicas reales (nit, documento, descripción de catálogo).
 *  - `fich_estados` incorpora metadatos del workflow (tipo, orden, color, es_final)
 *    que en el legacy estaban hardcodeados en PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 1. Estados del workflow  (legacy: estado_ficha)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_estados', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 40)->unique()
                ->comment('Slug estable para uso en código: borrador, autorizada, finalizada...');
            $table->string('descripcion', 100);
            $table->enum('tipo', ['ficha', 'actualizacion'])->default('ficha')
                ->comment('ficha = flujo original | actualizacion = flujo OS');
            $table->unsignedTinyInteger('orden')->default(0)
                ->comment('Posición en el flujo, para ordenar tableros');
            $table->string('color_hex', 7)->default('#6c757d');
            $table->boolean('es_editable')->default(false)
                ->comment('El generador puede editar la ficha en este estado');
            $table->boolean('es_final')->default(false)
                ->comment('Estado terminal: finalizada / cancelada');
            $table->boolean('cuenta_vigencia')->default(false)
                ->comment('Se evalúa fecha_fin para vigencia/vencimiento');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->index(['tipo', 'orden']);
        });

        // ─────────────────────────────────────────────────────────────────
        // 2. Objetos de contrato  (legacy: objetos_contrato)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_objetos_contrato', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 500);
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->index('estado');
        });

        // ─────────────────────────────────────────────────────────────────
        // 3. Tipos de servicio  (legacy: tipos_servicios)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_tipos_servicio', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 150)->unique();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->index('estado');
        });

        // ─────────────────────────────────────────────────────────────────
        // 4. Especialidades médicas  (legacy: especialidades)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_especialidades', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 255);
            $table->string('perfil', 60)->nullable()
                ->comment('ANESTESIA, CIRUJANO, FISIATRA, INSTRUMENTADOR...');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->unique('descripcion');
            $table->index(['estado', 'perfil']);
        });

        // ─────────────────────────────────────────────────────────────────
        // 5. Agremiaciones / prestadores  (legacy: agremiaciones)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_agremiaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 255);
            $table->string('nit', 20)->nullable()
                ->comment('Legacy era int(11): desbordaba NITs largos. Ahora string.');
            $table->string('rep_legal', 255)->nullable();
            $table->string('cc_rep_legal', 30)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('correo', 150)->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->unique('nit');
            $table->index('estado');
            $table->index('nombre');
        });

        // ─────────────────────────────────────────────────────────────────
        // 6. Profesionales de la salud  (legacy: profesionales)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_profesionales', function (Blueprint $table): void {
            $table->id();
            $table->string('documento', 20)
                ->comment('legacy profesionales.doc');
            $table->string('nombre', 255);
            $table->string('tarjeta_profesional', 60)->nullable()
                ->comment('legacy profesionales.tar_prof (RETHUS)');
            $table->string('correo', 150)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->unique('documento');
            $table->index('estado');
            $table->index('nombre');
        });

        // ─────────────────────────────────────────────────────────────────
        // 7. Profesional ↔ Especialidad  (legacy: inter_prof_esp)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_profesional_especialidad', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_profesional');
            $table->unsignedBigInteger('id_especialidad');
            $table->timestamps();

            $table->foreign('id_profesional', 'fk_fpe_profesional')
                ->references('id')->on('fich_profesionales')->cascadeOnDelete();
            $table->foreign('id_especialidad', 'fk_fpe_especialidad')
                ->references('id')->on('fich_especialidades')->cascadeOnDelete();

            // El legacy permitía duplicados: aquí se bloquean.
            $table->unique(['id_profesional', 'id_especialidad'], 'uq_fpe_prof_esp');
            $table->index('id_especialidad');
        });

        // ─────────────────────────────────────────────────────────────────
        // 8. Catálogo de observaciones de ítem  (legacy: obs_detalles_ficha)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_obs_items', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 500);
            $table->boolean('estado')->default(true);
            $table->unsignedBigInteger('usuario_crea_id')->nullable();
            $table->timestamps();

            $table->foreign('usuario_crea_id', 'fk_fobs_usuario_crea')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('estado');
        });

        // ─────────────────────────────────────────────────────────────────
        // 9. Observación ↔ Tipo de servicio  (legacy: obs_servicio_detalle)
        //    Filtra qué observaciones aplican a cada tipo de servicio.
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_obs_servicio_detalle', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_obs_item');
            $table->unsignedBigInteger('id_tipo_servicio');
            $table->timestamps();

            $table->foreign('id_obs_item', 'fk_fosd_obs_item')
                ->references('id')->on('fich_obs_items')->cascadeOnDelete();
            $table->foreign('id_tipo_servicio', 'fk_fosd_tipo_servicio')
                ->references('id')->on('fich_tipos_servicio')->cascadeOnDelete();

            $table->unique(['id_obs_item', 'id_tipo_servicio'], 'uq_fosd_obs_servicio');
            $table->index('id_tipo_servicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fich_obs_servicio_detalle');
        Schema::dropIfExists('fich_obs_items');
        Schema::dropIfExists('fich_profesional_especialidad');
        Schema::dropIfExists('fich_profesionales');
        Schema::dropIfExists('fich_agremiaciones');
        Schema::dropIfExists('fich_especialidades');
        Schema::dropIfExists('fich_tipos_servicio');
        Schema::dropIfExists('fich_objetos_contrato');
        Schema::dropIfExists('fich_estados');
    }
};
