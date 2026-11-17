<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinos de Teams para el tablero TIC.
 * Cada fila decide el canal y si avisa un grupo o el resumen de todos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glpi_teams_destinos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 120);
            $table->boolean('activo')->default(true);
            $table->string('modo', 20)->default('resumen');
            $table->json('grupos')->nullable();
            $table->unsignedSmallInteger('intervalo_minutos')->default(30);
            $table->boolean('omitir_si_vacio')->default(true);
            $table->text('webhook_cifrado')->nullable();
            $table->string('team_id', 80)->nullable();
            $table->string('team_nombre', 200)->nullable();
            $table->string('channel_id', 255)->nullable();
            $table->string('channel_nombre', 200)->nullable();
            $table->timestamp('ultima_notificacion_at')->nullable();
            $table->string('ultimo_estado', 20)->nullable();
            $table->string('ultimo_error', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by', 'fk_glpi_teams_dest_user')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glpi_teams_destinos');
    }
};
