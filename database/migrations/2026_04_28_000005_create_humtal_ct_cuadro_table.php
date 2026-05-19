<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_cuadro', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_grupo');
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes')->comment('1-12');
            $table->enum('estado', ['borrador', 'publicado', 'cerrado'])->default('borrador');
            $table->text('observaciones')->nullable();
            $table->unsignedBigInteger('creado_por');
            $table->unsignedBigInteger('publicado_por')->nullable();
            $table->timestamp('fecha_publicacion')->nullable();
            $table->unsignedBigInteger('cerrado_por')->nullable();
            $table->timestamp('fecha_cierre')->nullable();
            $table->timestamps();

            $table->foreign('id_grupo')
                  ->references('id')
                  ->on('humtal_ct_grupos')
                  ->restrictOnDelete();

            $table->foreign('creado_por')
                  ->references('id')
                  ->on('users')
                  ->restrictOnDelete();

            $table->foreign('publicado_por')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            $table->foreign('cerrado_por')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            $table->unique(['id_grupo', 'anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_cuadro');
    }
};
