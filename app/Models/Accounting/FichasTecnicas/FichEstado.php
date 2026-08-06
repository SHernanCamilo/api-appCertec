<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de estados del workflow de fichas técnicas.
 *
 * @property int         $id
 * @property string      $codigo
 * @property string      $descripcion
 * @property string      $tipo
 * @property int         $orden
 * @property string      $color_hex
 * @property bool        $es_editable
 * @property bool        $es_final
 * @property bool        $cuenta_vigencia
 * @property bool        $estado
 */
class FichEstado extends Model
{
    protected $table = 'fich_estados';

    protected $fillable = [
        'codigo',
        'descripcion',
        'tipo',
        'orden',
        'color_hex',
        'es_editable',
        'es_final',
        'cuenta_vigencia',
        'estado',
    ];

    protected $casts = [
        'orden'           => 'integer',
        'es_editable'     => 'boolean',
        'es_final'        => 'boolean',
        'cuenta_vigencia' => 'boolean',
        'estado'          => 'boolean',
    ];

    public function fichas(): HasMany
    {
        return $this->hasMany(FichFicha::class, 'id_estado');
    }

    /** Enum de dominio asociado a este registro. */
    public function toEnum(): EstadoFicha
    {
        return EstadoFicha::from($this->codigo);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', true);
    }

    public function scopeDelFlujo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }
}
