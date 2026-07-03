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
        Schema::create('inv_secuencias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_documento', 50)->unique();
            $table->string('prefijo', 10)->nullable();
            $table->integer('ultimo_numero')->default(0);
            $table->integer('longitud')->default(6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_secuencias');
    }
};
