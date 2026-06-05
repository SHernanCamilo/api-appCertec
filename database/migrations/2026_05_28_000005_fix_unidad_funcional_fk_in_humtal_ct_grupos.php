<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Corrige la FK de id_unidad_funcional en humtal_ct_grupos para que apunte
 * a la tabla correcta: config_unidades_funcionales
 * (la migración inicial intentaba apuntar a anti_unidades_funcionales que no existe)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Eliminar la FK existente si la hay
        $existingFk = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'humtal_ct_grupos'
              AND COLUMN_NAME = 'id_unidad_funcional'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($existingFk as $fk) {
            DB::statement("ALTER TABLE humtal_ct_grupos DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
        }

        // 2) Crear la FK correcta hacia config_unidades_funcionales
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            $table->foreign('id_unidad_funcional')
                  ->references('id')
                  ->on('config_unidades_funcionales')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            $table->dropForeign(['id_unidad_funcional']);
        });
    }
};
