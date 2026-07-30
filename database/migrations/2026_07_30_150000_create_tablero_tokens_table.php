<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de acceso público para tableros informativos (TVs en salas de espera).
 *
 * Cada token está vinculado a una sede y una vista específica. Un token robado
 * solo da acceso a la información de esa sede y puede revocarse individualmente
 * sin afectar las demás TVs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tablero_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique()->comment('Token secreto (se muestra una vez al crear)');
            $table->string('name', 150)->comment('Nombre descriptivo: ej. "TV Urgencias Neiva P1"');

            // Qué datos puede ver este token
            $table->string('schema_name', 10)->default('ug')->comment('Esquema de Fabric');
            $table->string('view_name', 150)->default('VW_HC_TableroUrgencias')->comment('Vista de Fabric');
            $table->string('sede_filter', 100)->nullable()->comment('Filtro de sede (null = todas)');

            // Seguridad y auditoría
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable()->comment('Null = no expira');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable()->comment('Última IP que usó este token');
            $table->unsignedInteger('use_count')->default(0);
            $table->unsignedSmallInteger('max_connections')->default(3)->comment('Máx SSE simultáneos con este token');

            // Quién lo creó
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['active', 'token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tablero_tokens');
    }
};
