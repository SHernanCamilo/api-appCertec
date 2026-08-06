<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de servicio médico (catálogo).
 *
 * @property int    $id
 * @property string $descripcion
 * @property bool   $estado
 */
class FichTipoServicio extends Model
{
    protected $table = 'fich_tipos_servicio';

    protected $fillable = ['descripcion', 'estado'];

    protected $casts = ['estado' => 'boolean'];

    public function detalles(): HasMany
    {
        return $this->hasMany(FichDetalle::class, 'id_tipo_servicio');
    }

    public function homologos(): HasMany
    {
        return $this->hasMany(FichHomologo::class, 'id_tipo_servicio');
    }

    /** Observaciones predefinidas aplicables a este tipo de servicio. */
    public function obsItems(): BelongsToMany
    {
        return $this->belongsToMany(
            FichObsItem::class,
            'fich_obs_servicio_detalle',
            'id_tipo_servicio',
            'id_obs_item'
        )->withTimestamps();
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', true);
    }
}
