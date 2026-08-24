<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de parametrización GLPI (Mesa de Servicio).
 *
 * Maestro de ANS por prioridad y categorías asociadas.
 * El validador contra reglas GLPI se implementa en una fase posterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glpi_param_plantillas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->unsignedBigInteger('id_empresa')->nullable();
            $table->string('nombre_entidad', 150)->nullable()
                ->comment('Departamento/entidad GLPI en texto; el ID se ligará en el validador');
            $table->string('grupo_tecnico', 150)->nullable()
                ->comment('Ej. Nivel 1 Nacional');
            $table->string('sla_asignacion', 150)->nullable()
                ->comment('Ej. GLOBAL AEROMAS');
            $table->string('prefijo_regla', 40)->default('TIC')
                ->comment('Prefijo para nombres de regla: BAJA TIC, MEDIA TIC…');
            $table->boolean('estado')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('id_empresa', 'fk_glpi_param_plt_empresa')
                ->references('id')->on('ent_empresas')->nullOnDelete();
            $table->foreign('created_by', 'fk_glpi_param_plt_user')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('estado');
            $table->index('id_empresa');
        });

        Schema::create('glpi_param_plantilla_ans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plantilla_id');
            $table->enum('prioridad', ['baja', 'media', 'alta', 'muy_alta']);
            $table->unsignedInteger('tiempo_asignacion')->nullable();
            $table->enum('unidad_asignacion', ['minuto', 'hora', 'dia'])->default('hora');
            $table->unsignedInteger('tiempo_solucion')->nullable();
            $table->enum('unidad_solucion', ['minuto', 'hora', 'dia'])->default('hora');
            $table->string('nombre_sla_solucion', 150)->nullable()
                ->comment('Ej. BAJA TIC');
            $table->string('nombre_regla', 150)->nullable()
                ->comment('Ej. BAJA TIC');
            $table->timestamps();

            $table->foreign('plantilla_id', 'fk_glpi_param_ans_plt')
                ->references('id')->on('glpi_param_plantillas')->cascadeOnDelete();
            $table->unique(['plantilla_id', 'prioridad'], 'uk_glpi_param_ans_prioridad');
        });

        Schema::create('glpi_param_plantilla_categorias', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plantilla_id');
            $table->string('categoria', 150);
            $table->string('subcategoria', 150);
            $table->enum('prioridad', ['baja', 'media', 'alta', 'muy_alta']);
            $table->string('ruta_completa', 350)->nullable();
            $table->unsignedBigInteger('glpi_itilcategories_id')->nullable()
                ->comment('ID de categoría en GLPI; se usa en el validador');
            $table->timestamps();

            $table->foreign('plantilla_id', 'fk_glpi_param_cat_plt')
                ->references('id')->on('glpi_param_plantillas')->cascadeOnDelete();
            $table->index(['plantilla_id', 'prioridad'], 'idx_glpi_param_cat_prioridad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glpi_param_plantilla_categorias');
        Schema::dropIfExists('glpi_param_plantilla_ans');
        Schema::dropIfExists('glpi_param_plantillas');
    }
};
