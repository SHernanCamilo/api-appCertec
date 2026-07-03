<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InvPedidoDetalle extends Model
{
    use HasFactory;

    protected $table = 'inv_pedido_detalles';

    protected $fillable = [
        'pedido_id', 'codigo_producto', 'producto_nombre', 'producto_tipo',
        'producto_marca', 'producto_promedio', 'producto_rotacion',
        'codigo_sanitario', 'cum_recibido', 'cantidad_solicitada',
        'cantidad_recibida', 'numero_lote', 'fecha_vencimiento',
        'precio_unitario', 'estado', 'aspecto_cumple', 'embalaje_cumple',
        'cadena_frio_temperatura', 'contenido_cumple', 'concepto_recepcion',
        'recibido_por', 'observaciones'
    ];

    public function pedido()
    {
        return $this->belongsTo(InvPedido::class, 'pedido_id');
    }

    public function recibidoPor()
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }
}
