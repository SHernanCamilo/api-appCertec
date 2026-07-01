<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de resolución cuando wf_aprobadores usa permiso_codigo:
 *   uf        → responsables de la UF del evento + permiso
 *   sucursal  → usuarios asignados a la sucursal de la UF + permiso
 *   sede      → usuarios asignados a la sede de la UF + permiso
 *   empresa   → usuarios de la empresa + permiso (default)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wf_aprobadores') && !Schema::hasColumn('wf_aprobadores', 'alcance')) {
            Schema::table('wf_aprobadores', function (Blueprint $table) {
                $table->string('alcance', 20)
                    ->nullable()
                    ->after('permiso_codigo')
                    ->comment('uf|sucursal|sede|empresa — acota permiso_codigo al contexto del evento');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wf_aprobadores', 'alcance')) {
            Schema::table('wf_aprobadores', function (Blueprint $table) {
                $table->dropColumn('alcance');
            });
        }
    }
};
