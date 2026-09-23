<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega id_empresa a humtal_ct_conceptos.
 *
 * Los conceptos del cuadro de turnos pasan a ser POR EMPRESA: cada empresa
 * controla sus propios conceptos. Nullable a nivel BD para no romper registros
 * existentes; la aplicacion lo exige (validacion required).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('humtal_ct_conceptos')
            && !Schema::hasColumn('humtal_ct_conceptos', 'id_empresa')) {
            Schema::table('humtal_ct_conceptos', function (Blueprint $table) {
                $table->unsignedBigInteger('id_empresa')->nullable()->after('id')
                    ->comment('Empresa dueña de este concepto');
                $table->index('id_empresa');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('humtal_ct_conceptos')
            && Schema::hasColumn('humtal_ct_conceptos', 'id_empresa')) {
            Schema::table('humtal_ct_conceptos', function (Blueprint $table) {
                $table->dropIndex(['id_empresa']);
                $table->dropColumn('id_empresa');
            });
        }
    }
};
