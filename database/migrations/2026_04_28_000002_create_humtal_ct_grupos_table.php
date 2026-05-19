<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->unsignedBigInteger('id_empresa');
            $table->unsignedBigInteger('id_sede')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_empresa')
                  ->references('id')
                  ->on('ent_empresas')
                  ->restrictOnDelete();

            $table->foreign('id_sede')
                  ->references('id')
                  ->on('config_ubi_sede')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_grupos');
    }
};
