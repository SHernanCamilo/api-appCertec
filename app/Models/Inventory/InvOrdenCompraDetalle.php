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
        'observaciones', 'estado',
    ];

    protected $casts = [
        'cantidad_solicitada_compra' => 'decimal:2',
        'precio_unitario_compra' => 'decimal:2',
    ];

    protected $appends = ['total_linea'];

    /**
     * Accessor: Total de la línea (cantidad × precio unitario)
     */
    public function getTotalLineaAttribute(): float
    {
        return round(
            ($this->cantidad_solicitada_compra ?? 0) * ($this->precio_unitario_compra ?? 0),
            2
        );
    }

    public function compra()
    {
        return $this->belongsTo(InvOrdenCompra::class, 'compra_id');
    }

    public function pedidoDetalle()
    {
        return $this->belongsTo(InvPedidoDetalle::class, 'pedido_detalle_id');
    }
}
