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
