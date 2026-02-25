<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('config_person_tercero', function (Blueprint $table) {
            $table->unsignedBigInteger('id_empresa')->nullable()->after('id');
            $table->unsignedBigInteger('id_sucursal')->nullable()->after('id_empresa');
            $table->unsignedBigInteger('id_sede')->nullable()->after('id_sucursal');
            
            // Foreign keys
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            $table->foreign('id_sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('cascade');
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('cascade');
            
            // Índices
            $table->index('id_empresa');
            $table->index('id_sucursal');
            $table->index('id_sede');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('config_person_tercero', function (Blueprint $table) {
            $table->dropForeign(['id_empresa']);
            $table->dropForeign(['id_sucursal']);
            $table->dropForeign(['id_sede']);
            
            $table->dropColumn(['id_empresa', 'id_sucursal', 'id_sede']);
        });
    }
};
