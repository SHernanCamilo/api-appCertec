<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la toma de inventario de activos fijos.
 *
 * El maestro de activos vive en Indigo y se consulta de solo lectura por la
 * vista de Fabric `ra.VW_Fixed_DetalleActivos`. Esta tabla guarda lo que el
 * inventariador encuentra en sitio: cada registro es una novedad fechada y
 * firmada, con el snapshot de cómo estaba el activo en ese momento.
 *
 * No se actualiza ni se borra: el historial completo del activo es la
 * secuencia de registros ordenada por fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inv_traz_activo')) {
            return;
        }

        Schema::create('inv_traz_activo', function (Blueprint $table) {
            $table->id();

            // ── Identificación del activo en Indigo ──────────────────────────
            $table->string('placa', 100);
            $table->string('serie', 150)->nullable();
            $table->string('articulo_codigo', 100)->nullable();
            $table->string('articulo_nombre', 255)->nullable();

            // Snapshot completo de la fila de Fabric al momento de la toma.
            // Permite auditar contra qué valores se comparó la novedad, incluso
            // si después el maestro de Indigo cambia.
            $table->json('valores_origen')->nullable();

            // ── Novedades reportadas (null = el campo no tuvo novedad) ───────
            $table->string('novedad_placa', 100)->nullable();
            $table->string('novedad_estado', 50)->nullable();
            $table->string('novedad_articulo', 255)->nullable();
            $table->string('novedad_marca', 150)->nullable();
            $table->string('novedad_modelo', 150)->nullable();
            $table->string('novedad_serie', 150)->nullable();
            $table->string('novedad_responsable', 255)->nullable();
            $table->string('novedad_localizacion', 255)->nullable();
            $table->string('novedad_tipo_inventario', 150)->nullable();
            $table->string('novedad_sucursal', 150)->nullable();
            $table->string('novedad_estado_fisico', 50)->nullable();

            $table->text('observacion')->nullable();

            // ── Contexto de la toma ──────────────────────────────────────────
            // Se guarda plano (sin FK) porque la sucursal/sede de origen viene
            // de Indigo y no siempre tiene equivalente en config_ubi_sede.
            $table->string('sucursal_origen', 150)->nullable();
            $table->unsignedBigInteger('id_empresa')->nullable();
            $table->unsignedBigInteger('id_sucursal')->nullable();

            $table->foreignId('registrado_por')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            // Historial de un activo: el acceso natural es por placa + fecha
            $table->index(['placa', 'created_at'], 'idx_traz_activo_placa_fecha');
            $table->index('serie', 'idx_traz_activo_serie');
            $table->index('registrado_por', 'idx_traz_activo_usuario');
            $table->index('novedad_estado_fisico', 'idx_traz_activo_estado_fisico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_traz_activo');
    }
};
