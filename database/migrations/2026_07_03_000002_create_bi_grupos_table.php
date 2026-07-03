<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_grupos')) {
            return;
        }

        Schema::create('bi_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique()->comment('Código del grupo. Ej: GG-BD-AA');
            $table->unsignedTinyInteger('tipo')->comment('1 o 2');
            $table->string('descripcion', 255)->nullable();
            $table->unsignedBigInteger('usuario_crea_id')->nullable();
            $table->unsignedBigInteger('usuario_modifica_id')->nullable();
            $table->timestamps();

            $table->foreign('usuario_crea_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('usuario_modifica_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_grupos');
    }
};
