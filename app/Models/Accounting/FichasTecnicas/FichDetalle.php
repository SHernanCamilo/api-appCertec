<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ítem de servicio/procedimiento de una ficha técnica.
 *
 * Legacy: `detalles_ficha`.
 *
 * @property int         $id
 * @property int         $id_ficha
 * @property string|null $tipo_liquidacion
 * @property string|null $tipo_servicio
 * @property int|null    $id_tipo_servicio
 * @property string|null $cups
 * @property string|null $grupo
 * @property string|null $subgrupo
 * @property string|null $forma_pago
 * @property string|null $homologo
 * @property string|null $variacion
 * @property string      $valor
 * @property int|null    $id_obs_item
 * @property string|null $novedad
 */
class FichDetalle extends Model
{
    protected $table = 'fich_detalles';

    protected $fillable = [
        'id_ficha',
        'tipo_liquidacion',
        'tipo_servicio',
        'id_tipo_servicio',
        'cups',
        'grupo',
        'subgrupo',
        'forma_pago',
        'homologo',
        'variacion',
        'valor',
        'id_obs_item',
        'novedad',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
    ];

    /** Formas de pago admitidas (legacy: campo libre `forma_pago`). */
    public const FORMAS_PAGO = ['MONTO FIJO', 'PRODUCCION', 'MENSUAL', 'EVENTO', 'PAQUETE'];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(FichFicha::class, 'id_ficha');
    }

    public function tipoServicioRelacion(): BelongsTo
    {
        return $this->belongsTo(FichTipoServicio::class, 'id_tipo_servicio');
    }

    public function obsItem(): BelongsTo
    {
        return $this->belongsTo(FichObsItem::class, 'id_obs_item');
    }

    /** Homólogo tarifario asociado por `code_manual`. */
    public function homologoRelacion(): BelongsTo
    {
        return $this->belongsTo(FichHomologo::class, 'homologo', 'code_manual');
    }
}
