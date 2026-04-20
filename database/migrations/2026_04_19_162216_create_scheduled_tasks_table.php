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
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // sync_activos, cierre_automatico, etc.
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->json('parameters')->nullable(); // Parámetros específicos de cada tarea
            $table->text('description')->nullable();
            $table->timestamp('scheduled_at')->nullable(); // Cuándo debe ejecutarse
            $table->timestamp('started_at')->nullable(); // Cuándo empezó
            $table->timestamp('completed_at')->nullable(); // Cuándo terminó
            $table->integer('attempts')->default(0); // Intentos de ejecución
            $table->integer('max_attempts')->default(3); // Máximo de intentos
            $table->text('result')->nullable(); // Resultado de la ejecución
            $table->text('error_message')->nullable(); // Mensaje de error si falló
            $table->unsignedBigInteger('job_id')->nullable(); // ID del job en la tabla jobs
            $table->unsignedBigInteger('created_by')->nullable(); // Usuario que creó la tarea
            $table->timestamps();
            $table->softDeletes();

            // Índices para mejorar performance
            $table->index('type');
            $table->index('status');
            $table->index('scheduled_at');
            $table->index(['status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
