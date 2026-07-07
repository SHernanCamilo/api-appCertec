<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_vistas')) {
            return;
        }

        Schema::create('bi_vistas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_bi_grupos');
            $table->string('nombre', 150);
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();

            $table->foreign('id_bi_grupos')
                ->references('id')
                ->on('bi_grupos')
                ->cascadeOnDelete();

            $table->unique(['id_bi_grupos', 'nombre'], 'bi_vistas_grupo_nombre_unique');
            $table->index('id_bi_grupos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_vistas');
    }
};
