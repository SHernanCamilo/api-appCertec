<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class AntiSolicitudDocumento extends Model
{
    protected $table = 'anti_solicitud_documentos';

    protected $fillable = [
        'id_solicitud',
        'tipo_documento',
        'nombre_archivo',
        'ruta_archivo',
        'subido_por',
    ];

    public function solicitud()
    {
        return $this->belongsTo(AntiSolicitud::class, 'id_solicitud');
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
