<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvIndigoItem extends Model
{
    protected $table = 'inv_indigo_items';
    public $timestamps = false;

    protected $fillable = [
        'numero_pedido', 'pedido_id', 'pedido_detalle_id', 'sucursal_id',
        'orden_compra', 'codigo_producto', 'proveedor',
        'cantidad_origen', 'cantidad_aplicada', 'fecha_indigo',
        'estado_orden', 'descripcion_orden',
    ];

    protected $casts = [
        'cantidad_origen'  => 'decimal:4',
        'cantidad_aplicada' => 'decimal:4',
        'fecha_indigo'     => 'datetime',
        'created_at'       => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(InvPedido::class, 'pedido_id');
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(InvPedidoDetalle::class, 'pedido_detalle_id');
    }
}
