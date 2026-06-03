<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar campos a wf_pasos para reglas por paso y contexto
        Schema::table('wf_pasos', function (Blueprint $table) {
            $table->json('reglas')->nullable()->after('requiere_monto');
            $table->text('descripcion_contexto')->nullable()->after('reglas');
        });

        // 2. Agregar relación de grupo a wf_aprobadores
        Schema::table('wf_aprobadores', function (Blueprint $table) {
            $table->unsignedBigInteger('id_grupo')->nullable()->after('prefijo_sucursal');
            $table->foreign('id_grupo')
                  ->references('id')
                  ->on('wf_grupos')
                  ->onDelete('set null');
        });

        // 3. Agregar campos de contexto y auditoría a wf_instancias
        Schema::table('wf_instancias', function (Blueprint $table) {
            $table->unsignedBigInteger('solicitante_id')->nullable()->after('modulo_record_id');
            $table->json('contexto')->nullable()->after('solicitante_id');
            $table->string('consecutivo')->nullable()->after('contexto');
            $table->timestamp('fecha_completado')->nullable()->after('updated_at');
            $table->timestamp('fecha_rechazado')->nullable()->after('fecha_completado');

            $table->foreign('solicitante_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('wf_pasos', function (Blueprint $table) {
            $table->dropColumn(['reglas', 'descripcion_contexto']);
        });

        Schema::table('wf_aprobadores', function (Blueprint $table) {
            $table->dropForeign(['id_grupo']);
            $table->dropColumn('id_grupo');
        });

        Schema::table('wf_instancias', function (Blueprint $table) {
            $table->dropForeign(['solicitante_id']);
            $table->dropColumn(['solicitante_id', 'contexto', 'consecutivo', 'fecha_completado', 'fecha_rechazado']);
        });
    }
};
