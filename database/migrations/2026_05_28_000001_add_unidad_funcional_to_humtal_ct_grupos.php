<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega id_unidad_funcional a humtal_ct_grupos.
 * 
 * Un grupo de trabajo pertenece a:
 * - Una empresa
 * - Una sede (ubicación física)
 * - Una unidad funcional (departamento/área)
 * 
 * Ejemplo: Grupo "Logística Turno A" → Medilaser → Sede Bogotá → Unidad Funcional Logística
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            // Agregar unidad funcional después de id_sede
            $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('id_sede');

            // Foreign key a config_unidades_funcionales (tabla real)
            $table->foreign('id_unidad_funcional')
                  ->references('id')
                  ->on('config_unidades_funcionales')
                  ->nullOnDelete();

            // Índice para búsquedas
            $table->index('id_unidad_funcional');
        });
    }

    public function down(): void
    {
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            $table->dropForeign(['id_unidad_funcional']);
            $table->dropIndex(['id_unidad_funcional']);
            $table->dropColumn('id_unidad_funcional');
        });
    }
};
