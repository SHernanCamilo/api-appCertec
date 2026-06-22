<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El aprobador ya no se elige al crear la solicitud: lo resuelve el motor wf_*.
 * La columna legacy id_user_aprobador queda opcional hasta que se apruebe (si aplica).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_horas_extra') || !Schema::hasColumn('event_horas_extra', 'id_user_aprobador')) {
            return;
        }

        Schema::table('event_horas_extra', function (Blueprint $table) {
            $table->unsignedBigInteger('id_user_aprobador')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('event_horas_extra') || !Schema::hasColumn('event_horas_extra', 'id_user_aprobador')) {
            return;
        }

        Schema::table('event_horas_extra', function (Blueprint $table) {
            $table->unsignedBigInteger('id_user_aprobador')->nullable(false)->change();
        });
    }
};
