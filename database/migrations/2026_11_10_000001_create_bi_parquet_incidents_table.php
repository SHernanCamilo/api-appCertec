<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidentes de generación de parquets: demoras (más de 5 minutos) y fallos.
 *
 * Un incidente se abre cuando:
 *   - un usuario pulsa "Actualizar" y la generación supera el tope de espera
 *     (source = user_refresh, reason = timeout),
 *   - un usuario pulsa "Actualizar" y la generación FALLA, por ejemplo error de
 *     consulta contra Fabric (source = user_refresh, reason = error; el motivo
 *     queda en `detail`), o
 *   - el vigilante (fabric:alert-slow-parquets) ve en Graph-Fabric una
 *     generación corriendo por encima del umbral (source = scheduler,
 *     reason = timeout).
 *
 * Los fallos del scheduler (cron) NO se guardan aquí: Graph-Fabric ya los
 * conserva en `last_error` y el monitor los muestra desde ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_parquet_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('schema_name', 20);
            $table->string('view_name', 150);
            $table->string('source', 20)->comment('user_refresh | scheduler');
            $table->string('reason', 10)->default('timeout')->comment('timeout (>5 min) | error (fallo de la consulta)');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 150)->nullable();
            $table->unsignedInteger('elapsed_s')->default(0);
            $table->string('stage', 40)->nullable()->comment('fase que reporto Graph-Fabric');
            $table->string('status', 15)->default('open')->comment('open | resolved');
            $table->string('detail', 500)->nullable()->comment('motivo del fallo o contexto');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['schema_name', 'view_name', 'status'], 'bi_pqi_view_status_idx');
            $table->index(['status', 'created_at'], 'bi_pqi_status_created_idx');
            $table->index('created_at', 'bi_pqi_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_parquet_incidents');
    }
};
