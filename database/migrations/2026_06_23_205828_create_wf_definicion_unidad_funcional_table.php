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
        Schema::create('wf_definicion_unidad_funcional', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_definicion');
            $table->unsignedBigInteger('id_unidad_funcional');
            $table->timestamps();

            $table->foreign('id_definicion')->references('id')->on('wf_definiciones')->onDelete('cascade');
            $table->foreign('id_unidad_funcional')->references('id')->on('config_unidades_funcionales')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wf_definicion_unidad_funcional');
    }
};
