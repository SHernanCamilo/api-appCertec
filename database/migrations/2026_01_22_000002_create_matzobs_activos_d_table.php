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
        Schema::create('matzobs_activos_d', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activo_c_id')->comment('FK a matzobs_activos_c');
            
            // Información general del equipo
            $table->string('marca', 100)->nullable()->comment('Marca del equipo');
            $table->string('tipo', 100)->nullable()->comment('Tipo de equipo');
            $table->string('referencia', 255)->nullable()->comment('Referencia o modelo del equipo');
            $table->string('tipo_unidad', 100)->nullable()->comment('Tipo de unidad');
            $table->date('fecha_compra')->nullable()->comment('Fecha de compra del equipo');
            $table->string('modalidad', 100)->nullable()->comment('Modalidad de adquisición');
            $table->string('proveedor', 255)->nullable()->comment('Proveedor del equipo');
            
            // Análisis de edad
            $table->integer('edad')->nullable()->comment('Edad del equipo en años');
            $table->integer('edad_v_util')->nullable()->comment('Vida útil esperada en años');
            $table->decimal('valoracion_edad', 5, 2)->nullable()->comment('Valoración de edad (0-100)');
            
            // Información de memoria RAM
            $table->decimal('tamano_ram', 8, 2)->nullable()->comment('Tamaño de RAM en GB');
            $table->string('generacion_ram', 50)->nullable()->comment('Generación de RAM (DDR3, DDR4, DDR5, etc.)');
            $table->decimal('valoracion_ram', 5, 2)->nullable()->comment('Valoración de RAM (0-100)');
            
            // Información del procesador
            $table->string('procesador', 255)->nullable()->comment('Modelo del procesador');
            $table->integer('numero_procesador')->nullable()->comment('Número de núcleos del procesador');
            $table->decimal('valoracion_procesador', 5, 2)->nullable()->comment('Valoración del procesador (0-100)');
            
            // Información de disco
            $table->string('tipo_disco', 100)->nullable()->comment('Tipo de disco (HDD, SSD, etc.)');
            $table->decimal('tamano_disco', 10, 2)->nullable()->comment('Tamaño del disco en GB');
            $table->string('interfaz_conexion', 100)->nullable()->comment('Interfaz de conexión del disco');
            $table->decimal('valoracion_disco', 5, 2)->nullable()->comment('Valoración del disco (0-100)');
            
            // Información de incidencias
            $table->integer('incidencias_6_meses')->default(0)->comment('Número de incidencias en los últimos 6 meses');
            
            $table->timestamps();
            
            // Relación con la tabla cabecera
            $table->foreign('activo_c_id')->references('id')->on('matzobs_activos_c')->onDelete('cascade');
            
            // Índices
            $table->index('activo_c_id');
            $table->index('marca');
            $table->index('tipo');
            $table->index('fecha_compra');
            $table->index('edad');
            $table->index('tamano_ram');
            $table->index('generacion_ram');
            $table->index('valoracion_edad');
            $table->index('valoracion_ram');
            $table->index('valoracion_procesador');
            $table->index('valoracion_disco');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matzobs_activos_d');
    }
};