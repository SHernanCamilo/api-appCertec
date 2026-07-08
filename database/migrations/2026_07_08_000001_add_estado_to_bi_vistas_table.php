<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bi_vistas')) {
            return;
        }

        Schema::table('bi_vistas', function (Blueprint $table) {
            if (!Schema::hasColumn('bi_vistas', 'estado')) {
                $table->string('estado', 20)->default('activo')->after('departamentos')
                    ->comment('activo, mantenimiento, inactivo');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bi_vistas')) {
            return;
        }

        Schema::table('bi_vistas', function (Blueprint $table) {
            if (Schema::hasColumn('bi_vistas', 'estado')) {
                $table->dropColumn('estado');
            }
        });
    }
};
