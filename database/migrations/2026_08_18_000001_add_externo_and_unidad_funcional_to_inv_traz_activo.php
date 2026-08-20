<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega soporte para activos "por fuera" (no están en el maestro de Indigo)
 * y la unidad funcional como campo de clasificación para filtros y reportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            // Indica si el activo fue registrado sin existir en el maestro de Indigo
            $table->boolean('es_externo')->default(false)->after('sucursal_origen');

            // Unidad funcional / centro de costo para clasificación y filtros
            $table->string('unidad_funcional', 255)->nullable()->after('es_externo');

            $table->index('es_externo', 'idx_traz_activo_es_externo');
            $table->index('unidad_funcional', 'idx_traz_activo_unidad_funcional');
        });
    }

    public function down(): void
    {
        Schema::table('inv_traz_activo', function (Blueprint $table) {
            $table->dropIndex('idx_traz_activo_es_externo');
            $table->dropIndex('idx_traz_activo_unidad_funcional');
            $table->dropColumn(['es_externo', 'unidad_funcional']);
        });
    }
};
