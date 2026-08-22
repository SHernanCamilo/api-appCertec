<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de "Excels guardados" (workbooks).
 *
 * Cada workbook es una combinacion de vistas que el usuario decidio abrir juntas.
 * Funciona como el concepto de "Archivo" en Excel Online: se guarda, se puede
 * reabrir y ya trae las vistas configuradas con sus hojas, formulas, filtros, etc.
 *
 * La metadata ligera se guarda aqui (nombre, descripcion, vistas incluidas).
 * El estado detallado de UI (columnas ocultas, zoom, filtros por hoja) sigue en
 * bi_workbook_states con una FK opcional hacia esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_workbooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name', 150)->comment('Nombre del workbook (visible al usuario)');
            $table->string('description', 500)->nullable()->comment('Descripcion breve');

            // Vistas incluidas: JSON array de { schema, viewName, label }
            // Ejemplo: [{"schema":"dc","viewName":"VW_Censo_Eal","label":"Censo Estancia"}]
            $table->json('views')->comment('Vistas de Fabric incluidas en este workbook');

            // Estado completo del workbook (sheets con formulas, filtros, columnas, etc.)
            // Se guarda aqui directamente porque un workbook multi-vista no cabe bien
            // en el esquema actual de bi_workbook_states (1 estado por schema+view).
            $table->json('state')->nullable()->comment('Estado UI completo: sheets, filters, pivot, formulas, zoom');

            $table->boolean('is_favorite')->default(false);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'updated_at'], 'bi_wb_user_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_workbooks');
    }
};
