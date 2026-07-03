<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * id_user_cubre solo aplica cuando la novedad requiere empleado a cubrir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_horas_extra') || !Schema::hasColumn('event_horas_extra', 'id_user_cubre')) {
            return;
        }

        Schema::table('event_horas_extra', function (Blueprint $table) {
            $table->unsignedBigInteger('id_user_cubre')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('event_horas_extra') || !Schema::hasColumn('event_horas_extra', 'id_user_cubre')) {
            return;
        }

        Schema::table('event_horas_extra', function (Blueprint $table) {
            $table->unsignedBigInteger('id_user_cubre')->nullable(false)->change();
        });
    }
};
