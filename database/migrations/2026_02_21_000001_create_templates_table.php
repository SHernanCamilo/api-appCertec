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
        if (!Schema::hasTable('templates')) {
            Schema::create('templates', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255)->comment('Nombre descriptivo de la plantilla');
                $table->string('category', 100)->comment('Categoría de la plantilla');
                $table->longText('content')->comment('Contenido HTML de la plantilla con variables');
                $table->unsignedBigInteger('created_by')->comment('ID del usuario que creó la plantilla');
                $table->timestamps();
                $table->softDeletes();
                
                // Índices para optimizar búsquedas
                $table->index('category');
                $table->index('created_by');
                $table->index('deleted_at');
                
                // Clave foránea
                $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
