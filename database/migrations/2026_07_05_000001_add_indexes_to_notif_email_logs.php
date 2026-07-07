<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices adicionales para performance en notif_email_logs.
 * Optimiza búsquedas de deduplicación y consultas del dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notif_email_logs', function (Blueprint $table) {
            // Búsqueda de duplicados: tipo + paciente + profesional + status
            $table->index(
                ['tipo', 'identificacion_paciente', 'profesional_nombre', 'status'],
                'idx_dedup_interconsulta'
            );

            // Dashboard: por fecha creación + status
            $table->index(['created_at', 'status'], 'idx_created_status');

            // Envío pendientes: status PENDING ordenados por fecha
            $table->index(['status', 'fecha_envio'], 'idx_pending_queue');

            // Bounce check: SENT + delivery PENDING + fecha_intento
            $table->index(['status', 'delivery_status', 'fecha_intento'], 'idx_bounce_check');
        });
    }

    public function down(): void
    {
        Schema::table('notif_email_logs', function (Blueprint $table) {
            $table->dropIndex('idx_dedup_interconsulta');
            $table->dropIndex('idx_created_status');
            $table->dropIndex('idx_pending_queue');
            $table->dropIndex('idx_bounce_check');
        });
    }
};
