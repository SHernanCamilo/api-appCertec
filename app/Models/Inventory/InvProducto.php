<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvProducto extends Model
{
    use HasFactory;

    protected $table = 'inv_productos';

    protected $fillable = [
        'codigo', 'nombre', 'tipo_producto', 'codigo_agrupador', 'agrupador',
        'fabricante', 'unidad_empaque', 'costo_promedio', 'ultimo_costo',
        'precio_venta', 'estado', 'tipo_riesgo', 'concentracion',
        'registro_sanitario', 'presentacion', 'activo'
    ];
}
