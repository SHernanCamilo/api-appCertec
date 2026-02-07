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
        // Agregar columnas faltantes a matzobs_activos_c
        Schema::table('matzobs_activos_c', function (Blueprint $table) {
            if (!Schema::hasColumn('matzobs_activos_c', 'estado')) {
                $table->boolean('estado')->default(1)->comment('Estado del activo (1=activo, 0=eliminado)');
            }
            
            if (!Schema::hasColumn('matzobs_activos_c', 'fecha_sincronizacion')) {
                $table->timestamp('fecha_sincronizacion')->nullable()->comment('Fecha de última sincronización con GLPI');
            }
            
            if (!Schema::hasColumn('matzobs_activos_c', 'fecha_eliminacion')) {
                $table->timestamp('fecha_eliminacion')->nullable()->comment('Fecha de eliminación del activo');
            }
        });

        // Verificar y ajustar la columna FK en matzobs_activos_d
        if (Schema::hasColumn('matzobs_activos_d', 'activo_c_id') && 
            !Schema::hasColumn('matzobs_activos_d', 'id_activo_c')) {
            
            // Agregar nueva columna
            Schema::table('matzobs_activos_d', function (Blueprint $table) {
                $table->unsignedBigInteger('id_activo_c')->nullable()->comment('FK a matzobs_activos_c');
            });
            
            // Copiar datos
            DB::statement('UPDATE matzobs_activos_d SET id_activo_c = activo_c_id');
            
            // Eliminar columna antigua
            Schema::table('matzobs_activos_d', function (Blueprint $table) {
                $table->dropColumn('activo_c_id');
            });
            
            // Hacer la nueva columna no nullable
            Schema::table('matzobs_activos_d', function (Blueprint $table) {
                $table->unsignedBigInteger('id_activo_c')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matzobs_activos_c', function (Blueprint $table) {
            $table->dropColumn(['estado', 'fecha_sincronizacion', 'fecha_eliminacion']);
        });

        if (Schema::hasColumn('matzobs_activos_d', 'id_activo_c')) {
            Schema::table('matzobs_activos_d', function (Blueprint $table) {
                $table->unsignedBigInteger('activo_c_id')->nullable();
            });
            
            DB::statement('UPDATE matzobs_activos_d SET activo_c_id = id_activo_c');
            
            Schema::table('matzobs_activos_d', function (Blueprint $table) {
                $table->dropColumn('id_activo_c');
                $table->unsignedBigInteger('activo_c_id')->nullable(false)->change();
            });
        }
    }
};