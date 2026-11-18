<?php

declare(strict_types=1);

namespace App\Models\Inventory\Ropa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RopaInventarioLinea extends Model
{
    protected $table = 'inv_ropa_inventario_lineas';

    protected $fillable = [
        'id_inventario',
        'id_prenda',
        'camas',
        'cama_extra',
        'constante',
        'cantidad',
        'estado',
    ];

    protected $casts = [
        'id_inventario' => 'integer',
        'id_prenda' => 'integer',
        'camas' => 'integer',
        'cama_extra' => 'integer',
        'constante' => 'decimal:2',
        'cantidad' => 'integer',
        'estado' => 'integer',
    ];

    public function inventario(): BelongsTo
    {
        return $this->belongsTo(RopaInventario::class, 'id_inventario');
    }

    public function prenda(): BelongsTo
    {
        return $this->belongsTo(RopaPrenda::class, 'id_prenda');
    }
}
