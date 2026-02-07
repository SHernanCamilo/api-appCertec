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
        Schema::create('seg_perfil_permiso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_perfil')->constrained('seg_perfiles')->onDelete('cascade');
            $table->foreignId('id_permiso')->constrained('seg_permisos')->onDelete('cascade');
            $table->timestamps();
            
            // Evitar duplicados
            $table->unique(['id_perfil', 'id_permiso']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seg_perfil_permiso');
    }
};
