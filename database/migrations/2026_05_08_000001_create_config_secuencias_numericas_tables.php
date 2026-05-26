<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: Módulo de Secuencias Numéricas
 * Tablas:
 *   - config_sec_patrones       → Catálogo de patrones reutilizables
 *   - config_sec_secuencias     → Cabecera de configuración por módulo/proceso
 *   - config_sec_detalles       → Detalle por unidad operativa (sucursal/sede)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. CATÁLOGO DE PATRONES DE SECUENCIA
        // ============================================================
        if (!Schema::hasTable('config_sec_patrones')) {
            Schema::create('config_sec_patrones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empresa_id');
                $table->string('nombre', 100)->comment('Nombre descriptivo del patrón. Ej: BOGOTA, GRAL 4 DIGITOS');
                $table->string('patron', 50)->comment('Patrón de secuencia. Ej: BTA####, ####, %Y%M-####');
                $table->string('descripcion', 255)->nullable()->comment('Descripción opcional del patrón');
                $table->boolean('estado')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('empresa_id')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

                $table->unique(['empresa_id', 'nombre'], 'uq_patron_nombre_empresa');
                $table->index('empresa_id');
                $table->index('estado');
            });
        }

        // ============================================================
        // 2. CABECERA DE SECUENCIA NUMÉRICA
        // ============================================================
        if (!Schema::hasTable('config_sec_secuencias')) {
            Schema::create('config_sec_secuencias', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('modulo_id')->comment('Módulo al que aplica la secuencia');
                $table->unsignedBigInteger('proceso_id')->nullable()->comment('Submódulo/proceso específico');
                $table->boolean('es_manual')->default(false)->comment('Si true, el usuario digita el número manualmente');
                $table->enum('ambito', ['empresa', 'sucursal', 'sede'])
                    ->default('empresa')
                    ->comment('Nivel organizacional al que aplica el detalle');
                $table->boolean('es_secuencial')->default(true)->comment('Si true, el consecutivo es continuo');
                $table->unsignedTinyInteger('rango')->default(4)->comment('Cantidad de dígitos del consecutivo (padding de ceros)');
                $table->boolean('estado')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('empresa_id')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->foreign('modulo_id')->references('id')->on('seg_modulos')->onDelete('cascade');
                $table->foreign('proceso_id')->references('id')->on('seg_modulos')->onDelete('set null');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

                $table->unique(['empresa_id', 'modulo_id', 'proceso_id'], 'uq_secuencia_empresa_modulo_proceso');
                $table->index('empresa_id');
                $table->index('modulo_id');
            });
        }

        // ============================================================
        // 3. DETALLE DE SECUENCIA POR UNIDAD OPERATIVA
        // ============================================================
        if (!Schema::hasTable('config_sec_detalles')) {
            Schema::create('config_sec_detalles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('secuencia_id');
                $table->unsignedBigInteger('patron_id')->comment('FK al catálogo de patrones');

                // Unidad operativa según el ámbito de la cabecera
                $table->unsignedBigInteger('sucursal_id')->nullable()->comment('Aplica cuando ambito = sucursal');
                $table->unsignedBigInteger('sede_id')->nullable()->comment('Aplica cuando ambito = sede');

                $table->unsignedBigInteger('siguiente_numero')->default(1)->comment('Próximo consecutivo a generar');
                $table->boolean('estado')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('secuencia_id')->references('id')->on('config_sec_secuencias')->onDelete('cascade');
                $table->foreign('patron_id')->references('id')->on('config_sec_patrones')->onDelete('restrict');
                $table->foreign('sucursal_id')->references('id')->on('config_ubi_sucursales')->onDelete('set null');
                $table->foreign('sede_id')->references('id')->on('config_ubi_sede')->onDelete('set null');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

                $table->index('secuencia_id');
                $table->index('patron_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_sec_detalles');
        Schema::dropIfExists('config_sec_secuencias');
        Schema::dropIfExists('config_sec_patrones');
    }
};
