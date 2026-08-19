<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InvOrdenCompra extends Model
{
    use HasFactory;

    protected $table = 'inv_ordenes_compra';

    protected $fillable = [
        'numero_orden_compra', 'fecha_orden', 'observaciones', 'proveedor_nombre',
        'estado', 'sincronizado_indigo', 'creado_por', 'oc_indigo'
    ];

    protected $appends = ['total', 'items_count', 'creado_por_nombre'];

    /**
     * Accessor: Total de la OC (suma de cantidad × precio de cada detalle)
     */
    public function getTotalAttribute(): float
    {
        return $this->detalles->sum(function ($detalle) {
            return ($detalle->cantidad_solicitada_compra ?? 0) * ($detalle->precio_unitario_compra ?? 0);
        });
    }

    /**
     * Accessor: Cantidad de ítems en la OC
     */
    public function getItemsCountAttribute(): int
    {
        return $this->detalles->count();
    }

    /**
     * Accessor: Nombre del creador
     */
    public function getCreadoPorNombreAttribute(): string
    {
        return $this->creador?->name ?? 'Administrador del Sistema';
    }

    public function detalles()
    {
        return $this->hasMany(InvOrdenCompraDetalle::class, 'compra_id');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
    
    public function recepciones()
    {
        return $this->hasMany(InvRecepcion::class, 'compra_id');
    }
}
