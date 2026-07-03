<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvPedidoTrazabilidad extends Model
{
    use HasFactory;

    protected $table = 'inv_pedidos_trazabilidad';

    protected $fillable = [
        'pedido_id', 'estado', 'comentarios', 'cambiado_por'
    ];

    public function pedido()
    {
        return $this->belongsTo(InvPedido::class, 'pedido_id');
    }

    public function usuario()
    {
        return $this->belongsTo(\App\Models\User::class, 'cambiado_por');
    }
}
