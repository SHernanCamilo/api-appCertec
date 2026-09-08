<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Forma de pago (plazo) parametrizable del contrato.
 *
 * @property int         $id
 * @property string      $descripcion
 * @property int|null    $dias
 * @property bool        $estado
 */
class FichFormaPago extends Model
{
    protected $table = 'fich_formas_pago';

    protected $fillable = ['descripcion', 'dias', 'estado'];

    protected $casts = [
        'dias'   => 'integer',
        'estado' => 'boolean',
    ];

    public function fichas(): HasMany
    {
        return $this->hasMany(FichFicha::class, 'id_forma_pago');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', true);
    }
}
