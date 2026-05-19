<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_grupo_empleado', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_grupo');
            $table->unsignedBigInteger('id_empleado');
            $table->date('fecha_ingreso');
            $table->date('fecha_salida')->nullable()->comment('null = activo en el grupo');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_grupo')
                  ->references('id')
                  ->on('humtal_ct_grupos')
                  ->cascadeOnDelete();

            $table->foreign('id_empleado')
                  ->references('id')
                  ->on('config_person_tercero')
                  ->restrictOnDelete();

            $table->unique(['id_grupo', 'id_empleado', 'fecha_ingreso'], 'uq_grupo_empleado_ingreso');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_grupo_empleado');
    }
};
