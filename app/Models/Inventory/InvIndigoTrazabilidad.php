<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InvIndigoTrazabilidad extends Model
{
    protected $table = 'inv_indigo_trazabilidad';

    protected $fillable = [
        'numero_pedido', 'sucursal_id', 'estado_indigo',
        'fecha_sincronizacion', 'diferencias_pendientes',
    ];

    protected $casts = [
        'fecha_sincronizacion' => 'datetime',
    ];
}
