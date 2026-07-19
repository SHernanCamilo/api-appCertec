<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvComprasPedido extends Model
{
    protected $table = 'inv_compras_pedidos';
    public $timestamps = false;

    protected $fillable = ['compra_id', 'pedido_id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(InvOrdenCompra::class, 'compra_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(InvPedido::class, 'pedido_id');
    }
}
