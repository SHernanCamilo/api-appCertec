<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla de referencia ISO 2859-1 para niveles de inspección de muestreo GMP.
 * Datos fijos — no cambian. Se usan para calcular el tamaño de muestra
 * en la recepción técnica según el tamaño del lote.
 */
class InvMuestreoNivel extends Model
{
    protected $table = 'inv_muestreo_niveles';
    public $timestamps = false;

    protected $fillable = [
        'nivel_inspeccion', 'lote_min', 'lote_max',
        'letra_codigo', 'tamano_muestra', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Obtener el tamaño de muestra para un lote dado (nivel II por defecto).
     */
    public static function getMuestraPorLote(int $tamanoLote, string $nivel = 'II'): ?int
    {
        $row = self::where('nivel_inspeccion', $nivel)
            ->where('lote_min', '<=', $tamanoLote)
            ->where('lote_max', '>=', $tamanoLote)
            ->where('activo', true)
            ->first();

        return $row?->tamano_muestra;
    }
}
