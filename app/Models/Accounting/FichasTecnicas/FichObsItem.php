<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catálogo de observaciones predefinidas para los ítems de una ficha.
 *
 * Legacy: `obs_detalles_ficha`.
 *
 * @property int         $id
 * @property string      $descripcion
 * @property bool        $estado
 * @property int|null    $usuario_crea_id
 */
class FichObsItem extends Model
{
    protected $table = 'fich_obs_items';

    protected $fillable = ['descripcion', 'estado', 'usuario_crea_id'];

    protected $casts = ['estado' => 'boolean'];

    public function usuarioCrea(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_crea_id');
    }

    /** Tipos de servicio a los que aplica esta observación. */
    public function tiposServicio(): BelongsToMany
    {
        return $this->belongsToMany(
            FichTipoServicio::class,
            'fich_obs_servicio_detalle',
            'id_obs_item',
            'id_tipo_servicio'
        )->withTimestamps();
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', true);
    }

    /**
     * Observaciones aplicables a un tipo de servicio.
     *
     * Reemplaza `generador/ajax/get_observaciones.php`.
     */
    public function scopeParaTipoServicio(Builder $query, int $idTipoServicio): Builder
    {
        return $query->whereHas(
            'tiposServicio',
            fn (Builder $q): Builder => $q->where('fich_tipos_servicio.id', $idTipoServicio)
        );
    }
}
