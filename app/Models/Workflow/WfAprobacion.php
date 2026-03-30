<?php

namespace App\Models\Workflow;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de aprobaciones/rechazos.
 * Trazabilidad completa del flujo.
 */
class WfAprobacion extends Model
{
    protected $table = 'wf_aprobaciones';

    protected $fillable = [
        'id_instancia',
        'id_paso',
        'id_user',
        'accion',
        'comentario',
        'monto_autorizado',
        'fecha_accion',
    ];

    protected $casts = [
        'monto_autorizado' => 'decimal:2',
        'fecha_accion' => 'datetime',
    ];

    const ACCION_APROBADO = 'aprobado';
    const ACCION_RECHAZADO = 'rechazado';
    const ACCION_OBSERVACION = 'observacion';
    const ACCION_DEVUELTO = 'devuelto';

    public function instancia()
    {
        return $this->belongsTo(WfInstancia::class, 'id_instancia');
    }

    public function paso()
    {
        return $this->belongsTo(WfPaso::class, 'id_paso');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function scopeAprobados($query)
    {
        return $query->where('accion', self::ACCION_APROBADO);
    }

    public function scopeRechazados($query)
    {
        return $query->where('accion', self::ACCION_RECHAZADO);
    }

    public function scopeOrdenadas($query)
    {
        return $query->orderBy('fecha_accion');
    }
}
