<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite múltiples sucursales/sedes por usuario en la misma empresa.
     */
    public function up(): void
    {
        // Primero, eliminar las FKs que dependen del índice UNIQUE
        \DB::statement('ALTER TABLE seg_empresa_user DROP FOREIGN KEY seg_empresa_user_user_id_foreign');
        \DB::statement('ALTER TABLE seg_empresa_user DROP FOREIGN KEY seg_empresa_user_empresa_id_foreign');
        
        Schema::table('seg_empresa_user', function (Blueprint $table) {
            $table->dropUnique('seg_empresa_user_user_id_empresa_id_unique');
            $table->unique(
                ['user_id', 'empresa_id', 'id_sucursal', 'id_sede'],
                'seg_empresa_user_user_empresa_sucursal_sede_unique'
            );
        });
        
        // Recrear las FKs
        Schema::table('seg_empresa_user', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('empresa_id')->references('id')->on('ent_empresas')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seg_empresa_user', function (Blueprint $table) {
            $table->dropUnique('seg_empresa_user_user_empresa_sucursal_sede_unique');
            $table->unique(['user_id', 'empresa_id'], 'seg_empresa_user_user_id_empresa_id_unique');
        });
    }
};
