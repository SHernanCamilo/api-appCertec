<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de rendimiento para la trazabilidad de activos fijos.
 *
 * - idx_traz_activo_fecha_id: cubre ORDER BY created_at DESC, id DESC (paginación)
 * - idx_traz_activo_placa_estado: cubre filtro combinado placa + estado_fisico
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = collect($sm->listTableIndexes('inv_traz_activo'))->keys();

            if (!$indexes->contains('idx_traz_activo_fecha_id')) {
                $table->index(['created_at', 'id'], 'idx_traz_activo_fecha_id');
            }

            if (!$indexes->contains('idx_traz_activo_placa_estado')) {
                $table->index(['placa', 'novedad_estado_fisico', 'created_at'], 'idx_traz_activo_placa_estado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            $table->dropIndex('idx_traz_activo_fecha_id');
            $table->dropIndex('idx_traz_activo_placa_estado');
        });
    }
};
