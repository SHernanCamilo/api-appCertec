<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_grupo_encargado', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_grupo');
            $table->unsignedBigInteger('id_user');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable()->comment('null = encargado actual');
            $table->string('motivo_cambio', 255)->nullable();
            $table->unsignedBigInteger('registrado_por');
            $table->timestamps();

            $table->foreign('id_grupo')
                  ->references('id')
                  ->on('humtal_ct_grupos')
                  ->cascadeOnDelete();

            $table->foreign('id_user')
                  ->references('id')
                  ->on('users')
                  ->restrictOnDelete();

            $table->foreign('registrado_por')
                  ->references('id')
                  ->on('users')
                  ->restrictOnDelete();

            // Índice para buscar encargado activo rápidamente
            $table->index(['id_grupo', 'fecha_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_grupo_encargado');
    }
};
