<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InvRecepcion extends Model
{
    use HasFactory;

    protected $table = 'inv_recepciones';

    protected $fillable = [
        'numero_recepcion', 'compra_id', 'numero_orden_compra', 'oc_indigo',
        'fecha_recepcion', 'recibido_por', 'total_items', 'observaciones', 'estado'
    ];

    public function compra()
    {
        return $this->belongsTo(InvOrdenCompra::class, 'compra_id');
    }

    public function detalles()
    {
        return $this->hasMany(InvRecepcionDetalle::class, 'recepcion_id');
    }

    public function recibidoPor()
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }
}
