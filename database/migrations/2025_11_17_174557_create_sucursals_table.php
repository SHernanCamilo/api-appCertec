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
        Schema::create('config_ubi_sucursales', function (Blueprint $table) {
            // 1. ID Primaria
            $table->id();
            
            // 2. Nombre de la sucursal
            $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->index('nombre');
            
            // 3. ID de la empresa (relación con ent_empresas)
            $table->unsignedBigInteger('id_Empresa');
            $table->foreign('id_Empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            $table->index('id_Empresa');
            
            // Timestamps (created_at, updated_at)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_ubi_sucursales');
    }
};
