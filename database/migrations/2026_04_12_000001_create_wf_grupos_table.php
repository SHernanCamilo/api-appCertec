<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de Aprobación para el Motor de Flujos.
 *
 * Permiten crear flujos independientes por:
 *   - Tipo de personal (Asistencial, Administrativo, Directivo)
 *   - Sucursal (NVA, EAL, TJA, FLA, MA)
 *   - Empresa (Medilaser, Jersalud)
 *
 * Un grupo agrupa cargos por jerarquía. Cada grupo puede tener
 * flujos diferentes con aprobadores diferentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Grupos de aprobación.
         * Ej: Asistencial, Administrativo, Directivo
         */
        Schema::create('wf_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique()->comment('asistencial, administrativo, directivo');
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->unsignedBigInteger('id_empresa')->nullable()->comment('null = aplica a todas');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('set null');
            $table->index(['id_empresa', 'estado']);
        });

        /**
         * Vinculación de cargos a grupos.
         * Un cargo pertenece a un solo grupo.
         */
        Schema::create('wf_grupo_cargos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_grupo');
            $table->unsignedBigInteger('id_cargo');
            $table->timestamps();

            $table->foreign('id_grupo')->references('id')->on('wf_grupos')->onDelete('cascade');
            $table->foreign('id_cargo')->references('id_cargo')->on('config_cargo')->onDelete('cascade');
            $table->unique(['id_grupo', 'id_cargo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wf_grupo_cargos');
        Schema::dropIfExists('wf_grupos');
    }
};
