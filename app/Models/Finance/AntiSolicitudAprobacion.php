<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class AntiSolicitudAprobacion extends Model
{
    protected $table = 'anti_solicitud_aprobaciones';

    protected $fillable = [
        'id_solicitud',
        'user_id',
        'rol_aprobador',
        'accion',
        'comentario',
        'monto_autorizado',
        'fecha_accion',
    ];

    protected $casts = [
        'monto_autorizado' => 'decimal:2',
        'fecha_accion'     => 'datetime',
    ];

    public function solicitud()
    {
        return $this->belongsTo(AntiSolicitud::class, 'id_solicitud');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
