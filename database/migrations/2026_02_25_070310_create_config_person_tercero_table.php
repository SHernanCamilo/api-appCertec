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
        Schema::create('config_person_tercero', function (Blueprint $table) {
            $table->id();
            $table->string('numero_identificacion', 50)->unique();
            $table->string('nombre', 255);
            $table->enum('tipo_identificacion', ['CC', 'CE', 'NIT', 'TI', 'PP', 'PEP'])->default('CC');
            $table->string('cargo', 100)->nullable();
            $table->string('unidad', 100)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
            
            // Índices
            $table->index('numero_identificacion');
            $table->index('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_person_tercero');
    }
};
