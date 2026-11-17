<?php

declare(strict_types=1);

namespace App\Models\MesaServicio;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class GlpiTeamsDestino extends Model
{
    public const MODO_RESUMEN = 'resumen';

    public const MODO_DETALLE = 'detalle';

    protected $table = 'glpi_teams_destinos';

    protected $fillable = [
        'nombre',
        'activo',
        'modo',
        'grupos',
        'intervalo_minutos',
        'programacion',
        'hora',
        'scheduled_task_id',
        'omitir_si_vacio',
        'webhook_cifrado',
        'team_id',
        'team_nombre',
        'channel_id',
        'channel_nombre',
        'ultima_notificacion_at',
        'ultimo_estado',
        'ultimo_error',
        'created_by',
    ];

    protected $hidden = [
        'webhook_cifrado',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'omitir_si_vacio' => 'boolean',
        'grupos' => 'array',
        'intervalo_minutos' => 'integer',
        'scheduled_task_id' => 'integer',
        'ultima_notificacion_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function webhook(): ?string
    {
        $cifrado = $this->webhook_cifrado;
        if (! is_string($cifrado) || $cifrado === '') {
            return null;
        }

        try {
            $plano = trim(Crypt::decryptString($cifrado));
        } catch (Throwable) {
            return null;
        }

        return $plano === '' ? null : $plano;
    }

    public function asignarWebhook(?string $url): void
    {
        $url = trim((string) $url);
        $this->webhook_cifrado = $url === '' ? null : Crypt::encryptString($url);
    }
}
