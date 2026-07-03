<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eliminar FK que apunta a users en config_unidades_fun_usuarios
        Schema::table('config_unidades_fun_usuarios', function (Blueprint $table) {
            $table->dropForeign(['id_user']);
        });

        // Eliminar FK que apunta a users en config_unidades_fun_responsable
        Schema::table('config_unidades_fun_responsable', function (Blueprint $table) {
            $table->dropForeign(['id_user']);
        });
    }

    public function down(): void
    {
        Schema::table('config_unidades_fun_usuarios', function (Blueprint $table) {
            $table->foreign('id_user')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('config_unidades_fun_responsable', function (Blueprint $table) {
            $table->foreign('id_user')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
