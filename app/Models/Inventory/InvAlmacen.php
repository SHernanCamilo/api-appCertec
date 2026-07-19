<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InvAlmacen extends Model
{
    protected $table = 'inv_almacenes';

    protected $fillable = [
        'codigo_almacen', 'nombre', 'sucursal', 'empresa', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorSucursal($query, string $sucursal)
    {
        return $query->where('sucursal', $sucursal);
    }
}
