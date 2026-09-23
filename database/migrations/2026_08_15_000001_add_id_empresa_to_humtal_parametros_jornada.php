<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega id_empresa a humtal_parametros_jornada.
 *
 * Los parametros de jornada pasan a ser POR EMPRESA: cada empresa controla
 * sus propios topes y franjas. Se agrega nullable a nivel de BD para no romper
 * registros existentes, pero la aplicacion lo exige (validacion required).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('humtal_parametros_jornada')
            && !Schema::hasColumn('humtal_parametros_jornada', 'id_empresa')) {
            Schema::table('humtal_parametros_jornada', function (Blueprint $table) {
                $table->unsignedBigInteger('id_empresa')->nullable()->after('id')
                    ->comment('Empresa dueña de estos parametros de jornada');
                $table->index('id_empresa');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('humtal_parametros_jornada')
            && Schema::hasColumn('humtal_parametros_jornada', 'id_empresa')) {
            Schema::table('humtal_parametros_jornada', function (Blueprint $table) {
                $table->dropIndex(['id_empresa']);
                $table->dropColumn('id_empresa');
            });
        }
    }
};
