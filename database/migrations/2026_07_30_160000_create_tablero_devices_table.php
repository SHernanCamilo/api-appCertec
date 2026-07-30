<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos (TVs) emparejados con tableros.
 *
 * Flujo de emparejamiento:
 *   1. Admin crea un registro con un código de 6 dígitos (válido 5 min)
 *   2. La TV ingresa el código → se genera un device_secret permanente
 *   3. La TV usa el device_secret para conectar SSE sin login
 *   4. El código se invalida inmediatamente (un solo uso)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tablero_devices', function (Blueprint $table) {
            $table->id();

            // Código de emparejamiento (6 dígitos, un solo uso, expira en 5 min)
            $table->string('pairing_code', 6)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->boolean('paired')->default(false);

            // Device secret (se genera al emparejar, permanente)
            $table->string('device_secret', 64)->nullable()->unique();

            // Configuración del tablero
            $table->string('name', 150)->comment('Ej: TV Urgencias Neiva P1');
            $table->string('schema_name', 10)->default('ug');
            $table->string('view_name', 150)->default('VW_HC_TableroUrgencias');
            $table->string('sede_filter', 100)->nullable();

            // Estado
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('max_connections')->default(2);

            // Auditoría
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('connection_count')->default(0);

            // Quién lo creó
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tablero_devices');
    }
};
