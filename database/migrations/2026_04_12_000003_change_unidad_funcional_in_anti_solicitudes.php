<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cambia id_unidad_funcional (FK) por unidad_funcional (texto) en anti_solicitudes.
 * La unidad se obtiene del campo 'unidad' del empleado, sincronizado desde el tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anti_solicitudes', function (Blueprint $table) {
            // Quitar FK y columna vieja
            if (Schema::hasColumn('anti_solicitudes', 'id_unidad_funcional')) {
                $table->dropForeign(['id_unidad_funcional']);
                $table->dropColumn('id_unidad_funcional');
            }

            // Agregar campo texto
            if (!Schema::hasColumn('anti_solicitudes', 'unidad_funcional')) {
                $table->string('unidad_funcional', 150)->nullable()
                      ->after('id_empleado')
                      ->comment('Unidad/Departamento del empleado (del tenant)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('anti_solicitudes', function (Blueprint $table) {
            if (Schema::hasColumn('anti_solicitudes', 'unidad_funcional')) {
                $table->dropColumn('unidad_funcional');
            }

            $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('id_empleado');
            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('restrict');
        });
    }
};
