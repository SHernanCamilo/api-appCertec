<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega id_empresa a config_unidades_funcionales.
 *
 * La tabla ya tiene id_sede. Ahora también queda asociada a una empresa.
 * Una unidad funcional pertenece a:
 *   - Una empresa (obligatorio)
 *   - Una sede (opcional, puede ser null si la unidad aplica a toda la empresa)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_unidades_funcionales', function (Blueprint $table) {
            if (!Schema::hasColumn('config_unidades_funcionales', 'id_empresa')) {
                $table->unsignedBigInteger('id_empresa')->nullable()->after('nombre');
                $table->foreign('id_empresa')
                      ->references('id')
                      ->on('ent_empresas')
                      ->nullOnDelete();
                $table->index('id_empresa');
            }
        });
    }

    public function down(): void
    {
        Schema::table('config_unidades_funcionales', function (Blueprint $table) {
            if (Schema::hasColumn('config_unidades_funcionales', 'id_empresa')) {
                $table->dropForeign(['id_empresa']);
                $table->dropIndex(['id_empresa']);
                $table->dropColumn('id_empresa');
            }
        });
    }
};
