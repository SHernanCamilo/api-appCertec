<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuracion de parquets por vista.
 *
 * Define cada cuanto Graph-Fabric debe regenerar el parquet de cada vista.
 * Laravel sincroniza esta tabla con POST /api/r2/schedule de Graph-Fabric
 * al guardar o cada 5 minutos via comando artisan.
 *
 * Prioridades:
 *   realtime  → cada 5 min (censos, datos criticos)
 *   high      → cada 15 min (operativo)
 *   medium    → cada 1h (reportes frecuentes)
 *   low       → cada 2h (analitico, historico)
 *   manual    → solo se regenera al pulsar "Actualizar" (force)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_parquet_config', function (Blueprint $table) {
            $table->id();
            $table->string('schema_name', 20);
            $table->string('view_name', 150);
            $table->unsignedSmallInteger('refresh_interval_min')->default(60)
                  ->comment('Cada cuantos minutos se regenera el parquet');
            $table->enum('priority', ['realtime', 'high', 'medium', 'low', 'manual'])
                  ->default('medium');
            $table->string('group_name', 50)->default('general')
                  ->comment('Grupo logico (censos, operativo, analitico, financiero...)');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable()
                  ->comment('Ultima vez que se sincronizo con Graph-Fabric');
            $table->unsignedInteger('estimated_rows')->nullable()
                  ->comment('Estimacion de filas (se actualiza desde Graph-Fabric)');
            $table->timestamps();

            $table->unique(['schema_name', 'view_name'], 'bi_pq_schema_view_unique');
            $table->index(['priority', 'enabled'], 'bi_pq_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_parquet_config');
    }
};
