<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\TipoManual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Homologación entre un código CUPS y su equivalente en un manual tarifario.
 *
 * Es la tabla de servicios contratables que realmente consume el generador
 * de fichas (legacy: `homologos`).
 *
 * @property int         $id
 * @property string      $code_cups
 * @property string      $desc_cups
 * @property string      $tipo_manual
 * @property string      $code_manual
 * @property string      $desc_manual
 * @property int|null    $id_tipo_servicio
 * @property string|null $uvr_grupo
 * @property string|null $vlr_cirujano
 * @property string|null $vlr_aneste
 * @property string|null $valor
 * @property bool        $pbs
 * @property bool        $estado
 */
class FichHomologo extends Model
{
    protected $table = 'fich_homologos';

    protected $fillable = [
        'code_cups',
        'desc_cups',
        'tipo_manual',
        'code_manual',
        'desc_manual',
        'id_tipo_servicio',
        'uvr_grupo',
        'vlr_cirujano',
        'vlr_aneste',
        'valor',
        'pbs',
        'observaciones',
        'estado',
    ];

    protected $casts = [
        'tipo_manual'  => TipoManual::class,
        'vlr_cirujano' => 'decimal:2',
        'vlr_aneste'   => 'decimal:2',
        'valor'        => 'decimal:2',
        'pbs'          => 'boolean',
        'estado'       => 'boolean',
    ];

    public function tipoServicio(): BelongsTo
    {
        return $this->belongsTo(FichTipoServicio::class, 'id_tipo_servicio');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', true);
    }

    public function scopeDeManual(Builder $query, TipoManual|string $manual): Builder
    {
        return $query->where('tipo_manual', $manual instanceof TipoManual ? $manual->value : $manual);
    }

    public function scopeDeCups(Builder $query, string $codeCups): Builder
    {
        return $query->where('code_cups', $codeCups);
    }

    /**
     * Búsqueda por código o descripción (CUPS o manual).
     *
     * Reemplaza `SELECT DISTINCT code_cups, desc_cups FROM homologos` sin
     * filtro del legacy, que traía las ~14.000 filas completas al navegador.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        if (ctype_digit($termino)) {
            return $query->where(function (Builder $q) use ($termino): void {
                $q->where('code_cups', 'like', "{$termino}%")
                    ->orWhere('code_manual', 'like', "{$termino}%");
            });
        }

        if (mb_strlen($termino) >= 3) {
            return $query->whereFullText(['desc_cups', 'desc_manual'], $termino);
        }

        return $query->where('desc_cups', 'like', "{$termino}%");
    }
}
