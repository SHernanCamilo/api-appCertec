<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Tarifarios y catálogos normativos.
 *
 * Mejora clave: el legacy tenía TRES tablas CUPS idénticas en estructura
 * (`cups_2077`, `cups_2336`, `cups_2641`) y el código hacía JOIN a una u otra
 * según la antigüedad de la ficha, generando duplicación de queries en cada PDF.
 * Aquí se unifican en `fich_cups` con una columna `resolucion`, de modo que un
 * solo JOIN parametrizado reemplaza las tres rutas.
 *
 * Igual con `soat_2023`: se generaliza a `fich_soat` con columna `vigencia`
 * para admitir tarifarios de años futuros sin crear tablas nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // CUPS unificado  (legacy: cups_2077 + cups_2336 + cups_2641)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_cups', function (Blueprint $table): void {
            $table->id();
            $table->enum('resolucion', ['2077', '2336', '2641'])
                ->comment('Resolución CUPS de origen. 2641 es la vigente.');
            $table->boolean('es_vigente')->default(false)
                ->comment('Marca la resolución en uso para nuevas fichas');

            $table->string('subcategoria', 10)->comment('Código CUPS');
            $table->string('desc_subcat', 500);
            $table->string('grupo', 3)->nullable();
            $table->string('desc_grup', 200)->nullable();
            $table->string('subgrupo', 4)->nullable();
            $table->string('desc_subg', 200)->nullable();
            $table->string('categoria', 5)->nullable();
            $table->string('desc_cat', 200)->nullable();
            $table->string('capitulo', 5)->nullable();
            $table->string('desc_cap', 200)->nullable();
            $table->string('tipo_serv', 200)->nullable();
            $table->string('pbs', 2)->nullable();
            $table->timestamps();

            $table->unique(['resolucion', 'subcategoria'], 'uq_fcups_resolucion_codigo');
            $table->index('subcategoria');
            $table->index(['resolucion', 'grupo']);
            $table->index(['resolucion', 'subgrupo']);
            $table->index(['es_vigente', 'subcategoria']);
        });

        // FULLTEXT para búsqueda por descripción (reemplaza LIKE '%...%')
        DB::statement('ALTER TABLE fich_cups ADD FULLTEXT ft_fcups_descripcion (desc_subcat)');

        // ─────────────────────────────────────────────────────────────────
        // Homologación CUPS ↔ Manual tarifario  (legacy: homologos)
        // Es la tabla de servicios contratables realmente usada por el
        // generador (ISS 2001 / SOAT / INSTITUCIONAL).
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_homologos', function (Blueprint $table): void {
            $table->id();
            $table->string('code_cups', 20);
            $table->string('desc_cups', 500);
            $table->enum('tipo_manual', ['ISS 2001', 'SOAT', 'INSTITUCIONAL'])
                ->comment('Manual tarifario de referencia');
            $table->string('code_manual', 20);
            $table->string('desc_manual', 500);
            $table->unsignedBigInteger('id_tipo_servicio')->nullable()
                ->comment('legacy homologos.id_tipo → tipos_servicios');
            $table->string('uvr_grupo', 30)->nullable()
                ->comment('Unidad de Valor Relativo / grupo quirúrgico');
            $table->decimal('vlr_cirujano', 14, 2)->nullable();
            $table->decimal('vlr_aneste', 14, 2)->nullable();
            $table->decimal('valor', 14, 2)->nullable();
            $table->boolean('pbs')->default(false);
            $table->text('observaciones')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_tipo_servicio', 'fk_fhom_tipo_servicio')
                ->references('id')->on('fich_tipos_servicio')->nullOnDelete();

            // El legacy validaba el duplicado a mano en PHP (new_serv.php).
            // Aquí lo garantiza la base de datos.
            $table->unique(['code_cups', 'code_manual'], 'uq_fhom_cups_manual');
            $table->index('code_cups');
            $table->index('code_manual');
            $table->index(['tipo_manual', 'code_manual']);
            $table->index(['estado', 'tipo_manual']);
        });

        DB::statement('ALTER TABLE fich_homologos ADD FULLTEXT ft_fhom_descripcion (desc_cups, desc_manual)');

        // ─────────────────────────────────────────────────────────────────
        // Tarifario SOAT  (legacy: soat_2023)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('fich_soat', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('vigencia')->default(2023)
                ->comment('Año del tarifario. Generaliza la tabla soat_YYYY del legacy.');
            $table->string('cod', 10);
            $table->string('descripcion', 500);
            $table->unsignedTinyInteger('grupo')->nullable();
            $table->decimal('vlr_cirujano', 14, 2)->default(0);
            $table->decimal('vlr_anestesia', 14, 2)->default(0);
            $table->decimal('valor', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['vigencia', 'cod'], 'uq_fsoat_vigencia_cod');
            $table->index('cod');
            $table->index(['vigencia', 'grupo']);
        });

        DB::statement('ALTER TABLE fich_soat ADD FULLTEXT ft_fsoat_descripcion (descripcion)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fich_soat');
        Schema::dropIfExists('fich_homologos');
        Schema::dropIfExists('fich_cups');
    }
};
