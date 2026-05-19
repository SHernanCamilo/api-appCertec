<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('humtal_ct_novedad_tipo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('afecta_turno')->default(true)->comment('si modifica el turno asignado');
            $table->boolean('requiere_reemplazo')->default(false);
            $table->boolean('requiere_aprobacion')->default(true);
            $table->string('color_hex', 7)->default('#E74C3C');
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('humtal_ct_novedad_tipo');
    }
};
