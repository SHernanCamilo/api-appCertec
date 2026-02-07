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
        Schema::create('matzobs_agentes', function (Blueprint $table) {
            $table->id();
            $table->string('tag', 100)->unique()->comment('Tag del agente GLPI');
            $table->unsignedBigInteger('id_empresa')->comment('ID de la empresa');
            $table->unsignedBigInteger('id_sucursal')->comment('ID de la sucursal');
            $table->unsignedBigInteger('id_sede')->nullable()->comment('ID de la sede (opcional)');
            $table->timestamps();
            
            // Índices básicos
            $table->index('tag');
            $table->index('id_empresa');
            $table->index('id_sucursal');
            $table->index('id_sede');
            
            // Claves foráneas con las tablas relacionadas
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            $table->foreign('id_sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('cascade');
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matzobs_agentes');
    }
};
