<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use App\Models\Finance\AntiConcepto;
use App\Models\Finance\AntiRegla;

class AntiSolicitudItem extends Model
{
    protected $table = 'anti_solicitud_items';

    protected $fillable = [
        'id_solicitud',
        'id_concepto',
        'id_regla',
        'descripcion',
        'cantidad',
        'valor_unitario',
        'valor_total',
    ];

    protected $casts = [
        'valor_unitario' => 'decimal:2',
        'valor_total'    => 'decimal:2',
        'cantidad'       => 'integer',
    ];

    public function solicitud()
    {
        return $this->belongsTo(AntiSolicitud::class, 'id_solicitud');
    }

    public function concepto()
    {
        return $this->belongsTo(AntiConcepto::class, 'id_concepto');
    }

    public function regla()
    {
        return $this->belongsTo(AntiRegla::class, 'id_regla');
    }
}
