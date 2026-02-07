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
        // Eliminar la tabla si existe para recrearla
        Schema::dropIfExists('matzobs_activos_c');
        
        Schema::create('matzobs_activos_c', function (Blueprint $table) {
            $table->id();
            $table->integer('id_activo_glpi')->unique()->comment('ID del activo en GLPI');
            $table->string('nombre_equipo', 255)->comment('Nombre del equipo');
            $table->unsignedBigInteger('id_empresa')->comment('ID de la empresa');
            $table->unsignedBigInteger('id_sede')->comment('ID de la sede');
            $table->unsignedBigInteger('id_sucursal')->nullable()->comment('ID de la sucursal donde se encuentra el equipo');
            $table->string('agente', 100)->nullable()->comment('Tag del agente GLPI');
            $table->string('placa', 100)->nullable()->comment('Placa o tag de inventario');
            $table->string('serial', 100)->nullable()->comment('Número de serie del equipo');
            $table->string('ubicacion', 255)->nullable()->comment('Ubicación física del equipo');
            $table->decimal('puntaje', 5, 2)->default(0)->comment('Puntaje de obsolescencia (0-100)');
            $table->string('usuario_modificacion', 100)->nullable()->comment('Usuario que realizó la última modificación');
            $table->timestamp('date_u_sincronizacion')->nullable()->comment('Fecha de última sincronización con GLPI');
            $table->timestamps(); // date_creation y date_update
            
            // Índices básicos
            $table->index('id_activo_glpi');
            $table->index('nombre_equipo');
            $table->index('id_empresa');
            $table->index('id_sede');
            $table->index('id_sucursal');
            $table->index('agente');
            $table->index('puntaje');
            $table->index('date_u_sincronizacion');
            
            // Claves foráneas con las tablas relacionadas
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('cascade');
            $table->foreign('id_sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matzobs_activos_c');
    }
};