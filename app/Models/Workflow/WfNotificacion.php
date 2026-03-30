<?php

namespace App\Models\Workflow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Notificaciones de flujo.
 * Alertas para aprobadores sobre solicitudes pendientes.
 */
class WfNotificacion extends Model
{
    protected $table = 'wf_notificaciones';

    protected $fillable = [
        'id_instancia',
        'id_user',
        'tipo',
        'mensaje',
        'leida',
        'fecha_lectura',
    ];

    protected $casts = [
        'leida' => 'boolean',
        'fecha_lectura' => 'datetime',
    ];

    const TIPO_PENDIENTE_APROBACION = 'pendiente_aprobacion';
    const TIPO_APROBADO = 'aprobado';
    const TIPO_RECHAZADO = 'rechazado';
    const TIPO_OBSERVACION = 'observacion';
    const TIPO_COMPLETADO = 'completado';

    public function instancia()
    {
        return $this->belongsTo(WfInstancia::class, 'id_instancia');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function scopeNoLeidas($query)
    {
        return $query->where('leida', false);
    }

    public function scopeLeidas($query)
    {
        return $query->where('leida', true);
    }

    public function scopePorUsuario($query, int $userId)
    {
        return $query->where('id_user', $userId);
    }

    public function marcarComoLeida(): void
    {
        $this->update([
            'leida' => true,
            'fecha_lectura' => now(),
        ]);
    }
}
