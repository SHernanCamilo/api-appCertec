<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Primero quitar la FK, luego hacer nullable, luego re-crear FK
        Schema::table('anti_solicitud_items', function (Blueprint $table) {
            $table->dropForeign(['id_regla']);
        });

        Schema::table('anti_solicitud_items', function (Blueprint $table) {
            $table->unsignedBigInteger('id_regla')->nullable()->change();
            $table->foreign('id_regla')->references('id')->on('anti_reglas')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('anti_solicitud_items', function (Blueprint $table) {
            $table->dropForeign(['id_regla']);
            $table->unsignedBigInteger('id_regla')->nullable(false)->change();
            $table->foreign('id_regla')->references('id')->on('anti_reglas')->onDelete('restrict');
        });
    }
};
