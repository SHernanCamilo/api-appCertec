<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tabla para gestionar permisos de Cuadro de Turnos por usuario/empresa/sede
     * Permite asignar permisos de forma granular
     */
    public function up(): void
    {
        Schema::create('seg_cuadro_turno_permisos', function (Blueprint $table) {
            $table->id();
            
            // Usuario que tiene el permiso
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Empresa
            $table->unsignedBigInteger('id_empresa');
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            
            // Sede (opcional - si es NULL, tiene permiso para todas las sedes de la empresa)
            $table->unsignedBigInteger('id_sede')->nullable();
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('cascade');
            
            // Tipo de permiso
            $table->enum('tipo_permiso', ['visualizar', 'crear', 'editar', 'eliminar', 'publicar', 'cerrar'])->default('visualizar');
            
            // Estado del permiso
            $table->boolean('activo')->default(true);
            
            // Auditoría
            $table->timestamps();
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->unsignedBigInteger('actualizado_por')->nullable();
            
            // Índices
            $table->unique(['user_id', 'id_empresa', 'id_sede', 'tipo_permiso'], 'uq_ctp_user_emp_sede_perm');
            $table->index(['user_id', 'activo']);
            $table->index(['id_empresa', 'activo']);
            $table->index(['id_sede', 'activo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seg_cuadro_turno_permisos');
    }
};
