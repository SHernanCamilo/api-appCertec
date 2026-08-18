<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvOrdenCompraDetalle extends Model
{
    use HasFactory;

    protected $table = 'inv_orden_compra_detalles';

    protected $fillable = [
        'compra_id', 'pedido_detalle_id', 'codigo_producto_indigo', 'producto_nombre',
        'clasificacion_venta', 'proveedor', 'cantidad_solicitada_compra',
        'fecha_entrega_estimada', 'clasificacion_vie', 'precio_unitario_compra',
        'observaciones', 'estado'
    ];

    public function compra()
    {
        return $this->belongsTo(InvOrdenCompra::class, 'compra_id');
    }

    public function pedidoDetalle()
    {
        return $this->belongsTo(InvPedidoDetalle::class, 'pedido_detalle_id');
    }
}
