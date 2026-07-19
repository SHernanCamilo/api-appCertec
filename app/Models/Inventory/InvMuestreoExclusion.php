<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * Productos excluidos del muestreo de recepción técnica.
 * Ej: agua destilada, guantes, insumos que no requieren inspección farmacéutica.
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
