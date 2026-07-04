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
        // Detectar y eliminar FKs existentes de forma segura
        $fks = collect(\DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'seg_empresa_user' 
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        "))->pluck('CONSTRAINT_NAME')->toArray();

        foreach ($fks as $fk) {
            \DB::statement("ALTER TABLE seg_empresa_user DROP FOREIGN KEY `{$fk}`");
        }

        // Eliminar unique anterior si existe
        $uniques = collect(\DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'seg_empresa_user' 
              AND CONSTRAINT_TYPE = 'UNIQUE'
        "))->pluck('CONSTRAINT_NAME')->toArray();

        foreach ($uniques as $uq) {
            \DB::statement("ALTER TABLE seg_empresa_user DROP INDEX `{$uq}`");
        }

        // Crear nuevo unique compuesto
        Schema::table('seg_empresa_user', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'empresa_id', 'id_sucursal', 'id_sede'],
                'seg_empresa_user_user_empresa_sucursal_sede_unique'
            );
        });

        // Recrear las FKs — asegurar que las columnas son BIGINT UNSIGNED antes
        \DB::statement('ALTER TABLE seg_empresa_user MODIFY user_id BIGINT UNSIGNED NOT NULL');
        \DB::statement('ALTER TABLE seg_empresa_user MODIFY empresa_id BIGINT UNSIGNED NOT NULL');

        \DB::statement('ALTER TABLE seg_empresa_user ADD CONSTRAINT seg_empresa_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        \DB::statement('ALTER TABLE seg_empresa_user ADD CONSTRAINT seg_empresa_user_empresa_id_foreign FOREIGN KEY (empresa_id) REFERENCES ent_empresas(id) ON DELETE CASCADE');
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
