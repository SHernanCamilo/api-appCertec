<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hace id_sede_origen nullable en anti_solicitudes.
 * No todos los empleados tienen sede asignada en su contexto.
 * La sede se puede inferir de la sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anti_solicitudes', function (Blueprint $table) {
            $table->unsignedBigInteger('id_sede_origen')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('anti_solicitudes', function (Blueprint $table) {
            $table->unsignedBigInteger('id_sede_origen')->nullable(false)->change();
        });
    }
};
