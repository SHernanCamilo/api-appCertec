<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina id_unidad_funcional de config_person_tercero.
 * La unidad del empleado se obtiene del campo 'unidad' (texto)
 * que se sincroniza desde el tenant de Microsoft (department).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_person_tercero', function (Blueprint $table) {
            if (Schema::hasColumn('config_person_tercero', 'id_unidad_funcional')) {
                $table->dropForeign(['id_unidad_funcional']);
                $table->dropIndex(['id_unidad_funcional']);
                $table->dropColumn('id_unidad_funcional');
            }
        });
    }

    public function down(): void
    {
        Schema::table('config_person_tercero', function (Blueprint $table) {
            $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('unidad');
            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('set null');
            $table->index('id_unidad_funcional');
        });
    }
};
