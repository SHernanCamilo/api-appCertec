<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Objeto de contratación de la ficha técnica.
 *
 * @property int    $id
 * @property string $descripcion
 * @property bool   $estado
 */
class FichObjetoContrato extends Model
{
    protected $table = 'fich_objetos_contrato';

    protected $fillable = ['descripcion', 'estado'];

    protected $casts = ['estado' => 'boolean'];

    public function fichas(): HasMany
    {
        return $this->hasMany(FichFicha::class, 'id_objeto_contrato');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', true);
    }
}
