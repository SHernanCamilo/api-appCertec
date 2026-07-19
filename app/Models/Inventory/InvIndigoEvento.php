<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InvIndigoEvento extends Model
{
    protected $table = 'inv_indigo_eventos';
    public $timestamps = false;

    protected $fillable = [
        'numero_pedido', 'orden_compra', 'codigo_producto',
        'sucursal_id', 'nivel', 'mensaje',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function info(string $mensaje, array $context = []): void
    {
        self::create(array_merge(['nivel' => 'info', 'mensaje' => $mensaje], $context));
    }

    public static function warning(string $mensaje, array $context = []): void
    {
        self::create(array_merge(['nivel' => 'warning', 'mensaje' => $mensaje], $context));
    }

    public static function error(string $mensaje, array $context = []): void
    {
        self::create(array_merge(['nivel' => 'error', 'mensaje' => $mensaje], $context));
    }
}
