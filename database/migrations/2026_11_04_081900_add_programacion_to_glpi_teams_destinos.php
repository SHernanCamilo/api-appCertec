<?php

declare(strict_types=1);

use App\Models\MesaServicio\GlpiTeamsDestino;
use App\Services\MesaServicio\Teams\GlpiTeamsProgramacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('glpi_teams_destinos', function (Blueprint $table): void {
            $table->string('programacion', 20)->default('intervalo')->after('intervalo_minutos');
            $table->string('hora', 5)->nullable()->after('programacion');
            $table->unsignedBigInteger('scheduled_task_id')->nullable()->after('hora');
            $table->index('scheduled_task_id', 'glpi_teams_destinos_task_idx');
        });

        $programacion = app(GlpiTeamsProgramacion::class);
        GlpiTeamsDestino::query()->each(function (GlpiTeamsDestino $destino) use ($programacion): void {
            $destino->intervalo_minutos = $programacion->intervalo((int) $destino->intervalo_minutos);
            $destino->programacion = 'intervalo';
            $destino->save();
            $programacion->sincronizar($destino);
        });
    }

    public function down(): void
    {
        $programacion = app(GlpiTeamsProgramacion::class);
        GlpiTeamsDestino::query()->each(function (GlpiTeamsDestino $destino) use ($programacion): void {
            $programacion->retirar($destino);
        });

        Schema::table('glpi_teams_destinos', function (Blueprint $table): void {
            $table->dropIndex('glpi_teams_destinos_task_idx');
            $table->dropColumn(['programacion', 'hora', 'scheduled_task_id']);
        });
    }
};
