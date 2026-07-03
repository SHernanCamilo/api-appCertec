<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('humtal_ct_cuadro', function (Blueprint $table) {
            // Agregar id_unidad_funcional
            if (!Schema::hasColumn('humtal_ct_cuadro', 'id_unidad_funcional')) {
                $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('id_grupo');
                $table->foreign('id_unidad_funcional')
                      ->references('id')
                      ->on('config_unidades_funcionales')
                      ->nullOnDelete();
            }
        });

        // Hacer id_grupo nullable para que los nuevos cuadros no lo necesiten
        DB::statement('ALTER TABLE humtal_ct_cuadro MODIFY id_grupo BIGINT UNSIGNED NULL');

        // Eliminar el unique constraint que incluye id_grupo (para permitir nulls)
        // y crear uno nuevo con id_unidad_funcional
        try {
            Schema::table('humtal_ct_cuadro', function (Blueprint $table) {
                $table->dropUnique(['id_grupo', 'anio', 'mes']);
            });
        } catch (\Exception $e) {
            // Si no existe el índice, ignorar
        }
    }

    public function down(): void
    {
        Schema::table('humtal_ct_cuadro', function (Blueprint $table) {
            if (Schema::hasColumn('humtal_ct_cuadro', 'id_unidad_funcional')) {
                $table->dropForeign(['id_unidad_funcional']);
                $table->dropColumn('id_unidad_funcional');
            }
        });

        DB::statement('ALTER TABLE humtal_ct_cuadro MODIFY id_grupo BIGINT UNSIGNED NOT NULL');
    }
};
