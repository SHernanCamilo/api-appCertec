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
        Schema::create('config_ubi_sede', function (Blueprint $table) {
            // 1. ID Primaria
            $table->id();
            
            // 2. Nombre de la sede
            $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->index('nombre');
            
            // 3. ID de la sucursal (relación con config_ubi_sucursales)
            $table->unsignedBigInteger('id_Sucursal');
            $table->foreign('id_Sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('cascade');
            $table->index('id_Sucursal');
            
            // Timestamps (created_at, updated_at)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_ubi_sede');
    }
};
