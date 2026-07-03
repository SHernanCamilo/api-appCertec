<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambia la restricción unique de 'codigo' en plantillas:
 * De: unique global (codigo)
 * A: unique compuesto (codigo, id_empresa)
 * 
 * Esto permite que cada empresa tenga sus propios códigos de plantilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('humtal_ct_plantillas', function (Blueprint $table) {
            // Eliminar el índice unique global
            $table->dropUnique(['codigo']);

            // Crear índice unique compuesto (codigo + id_empresa)
            $table->unique(['codigo', 'id_empresa'], 'plantillas_codigo_empresa_unique');
        });
    }

    public function down(): void
    {
        Schema::table('humtal_ct_plantillas', function (Blueprint $table) {
            $table->dropUnique('plantillas_codigo_empresa_unique');
            $table->unique('codigo');
        });
    }
};
