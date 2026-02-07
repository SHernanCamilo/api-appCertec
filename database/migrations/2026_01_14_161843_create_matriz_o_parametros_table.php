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
        Schema::create('matzobs_parametros', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_grupo');
            $table->string('nombre', 150);
            $table->string('valor', 100)->nullable();
            $table->string('frecuencia', 100)->nullable();
            $table->decimal('rango_i', 10, 2)->nullable()->comment('Rango inicial');
            $table->decimal('rango_f', 10, 2)->nullable()->comment('Rango final');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Foreign key
            $table->foreign('id_grupo')
                  ->references('id')
                  ->on('matzobs_grupo_parametros')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matzobs_parametros');
    }
};
