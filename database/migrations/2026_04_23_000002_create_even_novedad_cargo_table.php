<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: Relación Novedad ↔ Empresa / Cargo
 *
 * Crea la tabla:
 *   event_novedad_cargo  → define a qué empresa y/o cargo aplica cada novedad.
 *
 * Reglas de negocio:
 *   - empresa_id NULL  → aplica a todas las empresas
 *   - cargo_id   NULL  → aplica a todos los cargos
 *   - Ambos NULL       → novedad global (aplica a todos)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_novedad_cargo', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('novedad_id')
                ->comment('FK -> even_novedades');

            $table->unsignedBigInteger('empresa_id')->nullable()
                ->comment('FK -> empresas. NULL = todas las empresas');

            $table->unsignedBigInteger('cargo_id')->nullable()
                ->comment('FK -> config_cargo. NULL = todos los cargos');

            $table->boolean('activo')->default(1)
                ->comment('1 = activo, 0 = inactivo');

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────
            $table->foreign('novedad_id')
                ->references('id')->on('event_novedades')
                ->onDelete('cascade');

            $table->foreign('empresa_id')
                ->references('id')->on('ent_empresas')
                ->onDelete('cascade');

            $table->foreign('cargo_id')
                ->references('id_cargo')->on('config_cargo')
                ->onDelete('set null');

            // ── Índice único: evita duplicar la misma combinación ─────────────
            $table->unique(['novedad_id', 'empresa_id', 'cargo_id'], 'uq_novedad_empresa_cargo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_novedad_cargo');
    }
};
