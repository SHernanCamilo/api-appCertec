<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('config_person_tercero')) {
            return;
        }

        Schema::table('config_person_tercero', function (Blueprint $table) {
            if (!Schema::hasColumn('config_person_tercero', 'email')) {
                $table->string('email', 255)->nullable()->after('nombre');
            }

            if (!Schema::hasColumn('config_person_tercero', 'contrato')) {
                $table->string('contrato', 150)->nullable()->after('telefono');
            }

            if (!Schema::hasColumn('config_person_tercero', 'fecha_inicio_contrato')) {
                $table->date('fecha_inicio_contrato')->nullable()->after('contrato');
            }

            if (!Schema::hasColumn('config_person_tercero', 'fecha_fin_contrato')) {
                $table->date('fecha_fin_contrato')->nullable()->after('fecha_inicio_contrato');
            }

            if (!Schema::hasColumn('config_person_tercero', 'usuario_crea_id')) {
                $table->unsignedBigInteger('usuario_crea_id')->nullable();
                $table->foreign('usuario_crea_id')->references('id')->on('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('config_person_tercero', 'usuario_actualiza_id')) {
                $table->unsignedBigInteger('usuario_actualiza_id')->nullable();
                $table->foreign('usuario_actualiza_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('config_person_tercero')) {
            return;
        }

        Schema::table('config_person_tercero', function (Blueprint $table) {
            if (Schema::hasColumn('config_person_tercero', 'fecha_fin_contrato')) {
                $table->dropColumn('fecha_fin_contrato');
            }
            if (Schema::hasColumn('config_person_tercero', 'fecha_inicio_contrato')) {
                $table->dropColumn('fecha_inicio_contrato');
            }
            if (Schema::hasColumn('config_person_tercero', 'contrato')) {
                $table->dropColumn('contrato');
            }
        });
    }
};
