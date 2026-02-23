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
        if (!Schema::hasTable('matzobs_procesadores')) {
            Schema::create('matzobs_procesadores', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 200)->comment('Nombre del procesador');
                $table->integer('anio_lanzamiento')->nullable()->comment('Año de lanzamiento del procesador');
                $table->timestamps();
                
                $table->index('nombre');
                $table->index('anio_lanzamiento');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matzobs_procesadores');
    }
};
