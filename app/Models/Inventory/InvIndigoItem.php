<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvIndigoItem extends Model
{
    use HasFactory;

    protected $table = 'inv_indigo_items';
    public $timestamps = false; // We only have created_at handled manually or by DB

    protected $fillable = [
        'numero_pedido',
        'pedido_id',
        'pedido_detalle_id',
        'sucursal_id',
        'orden_compra',
        'codigo_producto',
        'proveedor',
        'cantidad_origen',
        'cantidad_aplicada',
        'fecha_indigo',
        'estado_orden',
        'descripcion_orden',
        'created_at'
    ];

    protected $casts = [
        'fecha_indigo' => 'datetime',
        'cantidad_origen' => 'decimal:4',
        'cantidad_aplicada' => 'decimal:4',
    ];
}
