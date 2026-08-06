<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarifario SOAT.
 *
 * Generaliza la tabla `soat_2023` del legacy mediante la columna `vigencia`.
 *
 * @property int    $id
 * @property int    $vigencia
 * @property string $cod
 * @property string $descripcion
 * @property int|null $grupo
 * @property string $vlr_cirujano
 * @property string $vlr_anestesia
 * @property string $valor
 */
class FichSoat extends Model
{
    protected $table = 'fich_soat';

    protected $fillable = [
        'vigencia',
        'cod',
        'descripcion',
        'grupo',
        'vlr_cirujano',
        'vlr_anestesia',
        'valor',
    ];

    protected $casts = [
        'vigencia'      => 'integer',
        'grupo'         => 'integer',
        'vlr_cirujano'  => 'decimal:2',
        'vlr_anestesia' => 'decimal:2',
        'valor'         => 'decimal:2',
    ];

    public function scopeDeVigencia(Builder $query, int $anio): Builder
    {
        return $query->where('vigencia', $anio);
    }

    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        if (ctype_digit($termino)) {
            return $query->where('cod', 'like', "{$termino}%");
        }

        if (mb_strlen($termino) >= 3) {
            return $query->whereFullText('descripcion', $termino);
        }

        return $query->where('descripcion', 'like', "{$termino}%");
    }
}
