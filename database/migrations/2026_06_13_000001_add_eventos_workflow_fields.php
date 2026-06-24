<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integración del módulo Eventos con el motor de flujos.
 *
 *  - wf_aprobadores.permiso_codigo: nueva estrategia de aprobador por permiso
 *    (seg_permisos.codigo, ej: 'apro-evento', 'auto-evento', 'digi-evento').
 *  - event_horas_extra.wf_instancia_id: vincula cada evento con su instancia de flujo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wf_aprobadores') && !Schema::hasColumn('wf_aprobadores', 'permiso_codigo')) {
            Schema::table('wf_aprobadores', function (Blueprint $table) {
                $table->string('permiso_codigo', 50)
                    ->nullable()
                    ->after('id_grupo')
                    ->comment('Estrategia 5: resuelve aprobadores por seg_permisos.codigo');
                $table->index('permiso_codigo');
            });
        }

        if (Schema::hasTable('event_horas_extra') && !Schema::hasColumn('event_horas_extra', 'wf_instancia_id')) {
            Schema::table('event_horas_extra', function (Blueprint $table) {
                $table->unsignedBigInteger('wf_instancia_id')
                    ->nullable()
                    ->after('estado')
                    ->comment('FK a wf_instancias: instancia de flujo del evento');
                $table->index('wf_instancia_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wf_aprobadores', 'permiso_codigo')) {
            Schema::table('wf_aprobadores', function (Blueprint $table) {
                $table->dropIndex(['permiso_codigo']);
                $table->dropColumn('permiso_codigo');
            });
        }

        if (Schema::hasColumn('event_horas_extra', 'wf_instancia_id')) {
            Schema::table('event_horas_extra', function (Blueprint $table) {
                $table->dropIndex(['wf_instancia_id']);
                $table->dropColumn('wf_instancia_id');
            });
        }
    }
};
