<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar columna id_user
        if (!Schema::hasColumn('config_person_tercero', 'id_user')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->unsignedBigInteger('id_user')->nullable()->after('id');
                $table->foreign('id_user')->references('id')->on('users')->nullOnDelete();
                $table->index('id_user');
            });
        }

        // 2. Vincular terceros existentes con users por numero_identificacion
        DB::statement("
            UPDATE config_person_tercero t
            INNER JOIN users u ON t.numero_identificacion = u.numero_identificacion
            SET t.id_user = u.id
            WHERE t.numero_identificacion IS NOT NULL
            AND t.numero_identificacion != ''
            AND t.id_user IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('config_person_tercero', function (Blueprint $table) {
            $table->dropForeign(['id_user']);
            $table->dropIndex(['id_user']);
            $table->dropColumn('id_user');
        });
    }
};
