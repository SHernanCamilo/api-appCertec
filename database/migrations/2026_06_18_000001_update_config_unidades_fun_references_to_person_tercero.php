<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('config_unidades_fun_usuarios')) {
            try {
                \Illuminate\Support\Facades\DB::statement('ALTER TABLE config_unidades_fun_usuarios DROP FOREIGN KEY config_unidades_fun_usuarios_id_user_foreign');
            } catch (\Exception $e) {
                // FK may not exist with that name — safe to ignore
            }
            Schema::table('config_unidades_fun_usuarios', function (Blueprint $table) {
                $table->foreign('id_user')
                    ->references('id')
                    ->on('config_person_tercero')
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable('config_unidades_fun_responsable')) {
            try {
                \Illuminate\Support\Facades\DB::statement('ALTER TABLE config_unidades_fun_responsable DROP FOREIGN KEY config_unidades_fun_responsable_id_user_foreign');
            } catch (\Exception $e) {
                // FK may not exist with that name — safe to ignore
            }
            Schema::table('config_unidades_fun_responsable', function (Blueprint $table) {
                $table->foreign('id_user')
                    ->references('id')
                    ->on('config_person_tercero')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('config_unidades_fun_usuarios')) {
            Schema::table('config_unidades_fun_usuarios', function (Blueprint $table) {
                $table->dropForeign(['id_user']);
                $table->foreign('id_user')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable('config_unidades_fun_responsable')) {
            Schema::table('config_unidades_fun_responsable', function (Blueprint $table) {
                $table->dropForeign(['id_user']);
                $table->foreign('id_user')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');
            });
        }
    }
};
