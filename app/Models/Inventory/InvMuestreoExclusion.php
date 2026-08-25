<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * Productos excluidos del muestreo estadístico ISO 2859-1 (tabla militar).
 * En recepción técnica implica inspección al 100% del lote (ej. Control Especial, Alto Costo).
 * Origen legacy: formula_magistral_muestra_exclusion
 */
class InvMuestreoExclusion extends Model
{
    protected $table = 'inv_muestreo_exclusiones';
    public $timestamps = false;

    protected $fillable = [
        'codigo_producto', 'nombre_producto', 'motivo', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Verificar si un producto está excluido del muestreo.
     */
    public static function isExcluded(string $codigoProducto): bool
    {
        return self::where('codigo_producto', $codigoProducto)
            ->where('activo', true)
            ->exists();
    }
}
