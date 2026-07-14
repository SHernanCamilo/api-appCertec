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
        // Tabla: anti_tipos
        if (!Schema::hasTable('anti_tipos')) {
            Schema::create('anti_tipos', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 50)->unique();
                $table->string('nombre', 100);
                $table->text('descripcion')->nullable();
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->index('codigo');
                $table->index('estado');
            });
        }

        // Tabla: anti_clases
        if (!Schema::hasTable('anti_clases')) {
            Schema::create('anti_clases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_tipo');
                $table->string('codigo', 50)->unique();
                $table->string('nombre', 100);
                $table->text('descripcion')->nullable();
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_tipo')->references('id')->on('anti_tipos')->onDelete('cascade');
                $table->index('id_tipo');
                $table->index('codigo');
                $table->index('estado');
            });
        }

        // Tabla: anti_modalidades
        if (!Schema::hasTable('anti_modalidades')) {
            Schema::create('anti_modalidades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_clase');
                $table->string('codigo', 50)->unique();
                $table->string('nombre', 100);
                $table->text('descripcion')->nullable();
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_clase')->references('id')->on('anti_clases')->onDelete('cascade');
                $table->index('id_clase');
                $table->index('codigo');
                $table->index('estado');
            });
        }

        // Tabla: anti_conceptos
        if (!Schema::hasTable('anti_conceptos')) {
            Schema::create('anti_conceptos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_tipo');
                $table->unsignedBigInteger('id_clase');
                $table->unsignedBigInteger('id_modalidad');
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_tipo')->references('id')->on('anti_tipos')->onDelete('cascade');
                $table->foreign('id_clase')->references('id')->on('anti_clases')->onDelete('cascade');
                $table->foreign('id_modalidad')->references('id')->on('anti_modalidades')->onDelete('cascade');
                
                $table->unique(['id_tipo', 'id_clase', 'id_modalidad'], 'unique_concepto');
                $table->index('id_tipo');
                $table->index('id_clase');
                $table->index('id_modalidad');
                $table->index('estado');
            });
        }

        // Tabla: anti_reglas
        if (!Schema::hasTable('anti_reglas')) {
            Schema::create('anti_reglas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_concepto');
                $table->string('descripcion', 255);
                $table->decimal('valor_tope', 15, 2);
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_concepto')->references('id')->on('anti_conceptos')->onDelete('cascade');
                $table->index('id_concepto');
                $table->index('estado');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anti_reglas');
        Schema::dropIfExists('anti_conceptos');
        Schema::dropIfExists('anti_modalidades');
        Schema::dropIfExists('anti_clases');
        Schema::dropIfExists('anti_tipos');
    }
};
