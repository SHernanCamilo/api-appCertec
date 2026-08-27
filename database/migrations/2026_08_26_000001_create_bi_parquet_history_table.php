<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de estado de parquets (trazabilidad).
 *
 * Cada snapshot registra el estado de una vista en un momento dado:
 * si se regeneró, cuánto tardó, qué carril, si quedó stale, etc.
 *
 * Se alimenta desde el comando artisan `fabric:snapshot-parquet-status`
 * que corre periódicamente y guarda el estado de Graph-Fabric.
 *
 * Permite responder:
 *   - ¿Cuáles vistas nunca se regeneran? (siempre stale)
 *   - ¿Cuál carril está represado?
 *   - ¿Cuánto tarda en promedio cada vista?
 *   - ¿Cuándo fue la última vez que una vista se generó OK?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_parquet_history', function (Blueprint $table) {
            $table->id();
            $table->string('schema_name', 20);
            $table->string('view_name', 150);
            $table->string('status', 30)
                  ->comment('ok, stale, generating, pending, error, missing');
            $table->string('lane', 20)->nullable()
                  ->comment('sprint, standard, heavy, marathon');
            $table->float('age_hours')->nullable();
            $table->float('avg_generation_s')->nullable();
            $table->float('size_mb')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->boolean('is_stale_by_config')->default(false)
                  ->comment('Si supera el refresh_interval_min configurado en Laravel');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('captured_at')->useCurrent()
                  ->comment('Momento del snapshot');

            $table->index(['schema_name', 'view_name'], 'bi_pqh_view_idx');
            $table->index(['status', 'captured_at'], 'bi_pqh_status_idx');
            $table->index('captured_at', 'bi_pqh_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_parquet_history');
    }
};
