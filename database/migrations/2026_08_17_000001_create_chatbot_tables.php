<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas del módulo ChatBot IA.
 *
 * - chatbot_knowledge_views: Catálogo de vistas que el bot puede consultar
 * - chatbot_conversations: Historial de conversaciones
 * - chatbot_messages: Mensajes individuales de cada conversación
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Catálogo de conocimiento (qué vistas puede consultar el bot) ────
        if (!Schema::hasTable('chatbot_knowledge_views')) {
            Schema::create('chatbot_knowledge_views', function (Blueprint $table) {
            $table->id();
            $table->string('schema_name', 20)->comment('Esquema Fabric: in, dc, fr, co, rf, etc.');
            $table->string('view_name', 150)->comment('Nombre de la vista en Fabric');
            $table->string('descripcion', 500)->comment('Qué contiene esta vista, en lenguaje natural');
            $table->json('columnas_clave')->nullable()->comment('Columnas principales con descripción');
            $table->json('ejemplo_preguntas')->nullable()->comment('Preguntas ejemplo que esta vista responde');
            $table->json('filtros_sugeridos')->nullable()->comment('Filtros recomendados con descripción');
            $table->text('notas_negocio')->nullable()->comment('Reglas de negocio o aclaraciones');
            $table->string('grupo_requerido', 50)->nullable()->comment('Grupo GG-BD-* necesario para acceder');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['schema_name', 'view_name'], 'uq_chatbot_schema_view');
            $table->index('activo');
            $table->index('schema_name');
            });
        }

        // ─── Conversaciones ──────────────────────────────────────────────────
        if (!Schema::hasTable('chatbot_conversations')) {
            Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('titulo', 200)->nullable()->comment('Título auto-generado del chat');
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'activa']);
            });
        }

        // ─── Mensajes ────────────────────────────────────────────────────────
        if (!Schema::hasTable('chatbot_messages')) {
            Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chatbot_conversations')->onDelete('cascade');
            $table->enum('role', ['user', 'assistant', 'system'])->default('user');
            $table->text('content')->comment('Contenido del mensaje');
            $table->json('metadata')->nullable()->comment('Tool calls, queries ejecutadas, tokens usados');
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            });
        }

        // ─── Log de seguridad (intentos de prompt injection, accesos denegados) ─
        if (!Schema::hasTable('chatbot_security_logs')) {
            Schema::create('chatbot_security_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('tipo', 50)->comment('injection_attempt, access_denied, schema_violation');
            $table->text('mensaje_original')->comment('El mensaje que envió el usuario');
            $table->text('detalle')->nullable()->comment('Por qué se bloqueó');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tipo']);
            $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_security_logs');
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_conversations');
        Schema::dropIfExists('chatbot_knowledge_views');
    }
};
