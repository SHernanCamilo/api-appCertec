<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_plantillas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->decimal('duracion_horas', 4, 2);
            $table->boolean('es_nocturno')->default(false);
            $table->string('color_hex', 7)->default('#3498DB');
            $table->unsignedBigInteger('id_empresa')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_empresa')
                  ->references('id')
                  ->on('ent_empresas')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_plantillas');
    }
};
