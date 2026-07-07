<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notif_email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50); // INTERCONSULTA_SOLICITUD, INTERCONSULTA_ANULACION
            $table->string('identificacion_paciente', 20);
            $table->string('nombre_paciente', 200)->nullable();
            $table->string('profesional_nombre', 200);
            $table->string('email_to', 150);
            $table->string('subject', 500);
            $table->longText('body')->nullable();
            $table->enum('status', ['PENDING', 'SENT', 'ERROR', 'EXPIRED'])->default('PENDING');
            $table->enum('delivery_status', ['PENDING', 'DELIVERED', 'BOUNCED', 'FAILED'])->default('PENDING');
            $table->string('message_id', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->text('bounce_reason')->nullable();
            $table->tinyInteger('intentos')->default(0);
            $table->timestamp('fecha_envio')->nullable();
            $table->timestamp('fecha_intento')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounce_detected_at')->nullable();

            // Datos contexto de la interconsulta
            $table->string('ingreso', 50)->nullable();
            $table->string('clinica', 150)->nullable();
            $table->string('unidad_funcional', 150)->nullable();
            $table->string('cama', 50)->nullable();
            $table->string('orden', 50)->nullable();
            $table->string('especialidad', 150)->nullable();
            $table->text('diagnostico')->nullable();
            $table->string('folio', 50)->nullable();
            $table->string('estado_orden', 50)->nullable(); // SOLICITADO/ANULADO
            $table->dateTime('fecha_orden')->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            // Índices
            $table->index(['tipo', 'identificacion_paciente'], 'idx_tipo_identificacion');
            $table->index(['email_to', 'status'], 'idx_email_status');
            $table->index(['status', 'delivery_status'], 'idx_delivery');
            $table->index('fecha_orden', 'idx_fecha_orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notif_email_logs');
    }
};
