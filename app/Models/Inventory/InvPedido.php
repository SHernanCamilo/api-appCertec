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
        'sucursal_id', 'solicitado_por', 'recibido_por', 'aprobado_por', 'cancelado_por'
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

    public function aprobador()
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /**
     * Nombre del usuario que creó el pedido (para la auditoría del detalle).
     * Se expone en el JSON como 'solicitado_por_nombre'.
     */
    protected $appends = ['solicitado_por_nombre', 'aprobado_por_nombre'];

    public function getSolicitadoPorNombreAttribute(): ?string
    {
        return $this->solicitante?->name;
    }

    public function getAprobadoPorNombreAttribute(): ?string
    {
        return $this->aprobador?->name;
    }
}
