<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InvPedido extends Model
{
    use HasFactory;

    protected $table = 'inv_pedidos';

    protected $fillable = [
        'numero_pedido', 'proveedor', 'fecha_pedido', 'fecha_esperada',
        'fecha_recibido', 'estado', 'total_articulos', 'observaciones',
        'solicitado_por', 'recibido_por', 'aprobado_por', 'cancelado_por'
    ];

    public function detalles()
    {
        return $this->hasMany(InvPedidoDetalle::class, 'pedido_id');
    }

    public function trazabilidad()
    {
        return $this->hasMany(InvPedidoTrazabilidad::class, 'pedido_id')->orderBy('created_at', 'desc');
    }

    public function solicitante()
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }
}
