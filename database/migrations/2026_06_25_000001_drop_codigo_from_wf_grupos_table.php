<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wf_grupos', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });

        Schema::table('wf_grupos', function (Blueprint $table) {
            $table->unique(['id_empresa', 'nombre'], 'wf_grupos_empresa_nombre_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wf_grupos', function (Blueprint $table) {
            $table->dropUnique('wf_grupos_empresa_nombre_unique');
        });

        Schema::table('wf_grupos', function (Blueprint $table) {
            $table->string('codigo', 50)->nullable()->after('id');
        });

        // Restaurar códigos derivados del nombre para filas existentes
        \Illuminate\Support\Facades\DB::statement(
            "UPDATE wf_grupos SET codigo = LOWER(REPLACE(nombre, ' ', '_')) WHERE codigo IS NULL"
        );

        Schema::table('wf_grupos', function (Blueprint $table) {
            $table->string('codigo', 50)->nullable(false)->change();
            $table->unique('codigo');
        });
    }
};
