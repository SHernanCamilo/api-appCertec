<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de logs de errores de vistas BI.
 * Registra TIMEOUT y FABRIC_ERROR para monitoreo, auto-mantenimiento y alertas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_vista_error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('schema_name', 20)->index();
            $table->string('view_name', 150)->index();
            $table->enum('error_type', ['timeout', 'fabric_error', 'permission', 'unknown'])
                ->default('unknown')
                ->index();
            $table->string('error_category', 50)->nullable()
                ->comment('TIMEOUT, FABRIC_ERROR, CIRCUIT_OPEN, etc.');
            $table->text('error_message')->nullable();
            $table->text('error_detail')->nullable()
                ->comment('Detalle SQL o cURL del error');
            $table->string('user_email', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->integer('elapsed_ms')->nullable()
                ->comment('Tiempo antes del error (para timeouts)');
            $table->boolean('auto_maintenance_applied')->default(false)
                ->comment('Se puso la vista en mantenimiento automaticamente');
            $table->boolean('notification_sent')->default(false)
                ->comment('Se envio email de alerta a los admins');
            $table->string('resolved_by', 100)->nullable()
                ->comment('Email del admin que resolvio/quito mantenimiento');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['schema_name', 'view_name', 'created_at']);
            $table->index(['error_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_vista_error_logs');
    }
};
