<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Actualizar tabla matzobs_activos_c para sincronización GLPI
        Schema::table('matzobs_activos_c', function (Blueprint $table) {
            // Agregar campos necesarios para sincronización
            if (!Schema::hasColumn('matzobs_activos_c', 'estado')) {
                $table->boolean('estado')->default(1)->comment('Estado del activo (1=activo, 0=eliminado)');
            }
            
            if (!Schema::hasColumn('matzobs_activos_c', 'fecha_sincronizacion')) {
                $table->timestamp('fecha_sincronizacion')->nullable()->comment('Fecha de última sincronización con GLPI');
            }
            
            if (!Schema::hasColumn('matzobs_activos_c', 'fecha_eliminacion')) {
                $table->timestamp('fecha_eliminacion')->nullable()->comment('Fecha de eliminación del activo');
            }
            
            // Modificar campos existentes si es necesario
            $table->unsignedBigInteger('id_empresa')->nullable()->change();
            $table->unsignedBigInteger('id_sede')->nullable()->change();
        });

        // Actualizar tabla matzobs_activos_d para sincronización GLPI
        Schema::table('matzobs_activos_d', function (Blueprint $table) {
            // Cambiar nombre de la columna FK para consistencia
            if (Schema::hasColumn('matzobs_activos_d', 'activo_c_id') && 
                !Schema::hasColumn('matzobs_activos_d', 'id_activo_c')) {
                $table->renameColumn('activo_c_id', 'id_activo_c');
            }
        });

        // Agregar índices adicionales para optimizar consultas de sincronización
        Schema::table('matzobs_activos_c', function (Blueprint $table) {
            if (!$this->indexExists('matzobs_activos_c', 'matzobs_activos_c_estado_index')) {
                $table->index('estado');
            }
            
            if (!$this->indexExists('matzobs_activos_c', 'matzobs_activos_c_fecha_sincronizacion_index')) {
                $table->index('fecha_sincronizacion');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matzobs_activos_c', function (Blueprint $table) {
            $table->dropColumn(['estado', 'fecha_sincronizacion', 'fecha_eliminacion']);
            $table->dropIndex(['estado']);
            $table->dropIndex(['fecha_sincronizacion']);
        });

        Schema::table('matzobs_activos_d', function (Blueprint $table) {
            if (Schema::hasColumn('matzobs_activos_d', 'id_activo_c')) {
                $table->renameColumn('id_activo_c', 'activo_c_id');
            }
        });
    }

    /**
     * Check if an index exists on a table
     */
    private function indexExists($table, $index)
    {
        $indexes = Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($table);
        return array_key_exists($index, $indexes);
    }
};