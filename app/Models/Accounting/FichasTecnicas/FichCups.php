<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Clasificación Única de Procedimientos en Salud (CUPS).
 *
 * Unifica las tres tablas del legacy (`cups_2077`, `cups_2336`, `cups_2641`)
 * en una sola con la columna `resolucion`.
 *
 * @property int         $id
 * @property string      $resolucion
 * @property bool        $es_vigente
 * @property string      $subcategoria
 * @property string      $desc_subcat
 * @property string|null $grupo
 * @property string|null $desc_grup
 * @property string|null $subgrupo
 * @property string|null $desc_subg
 */
class FichCups extends Model
{
    protected $table = 'fich_cups';

    protected $fillable = [
        'resolucion',
        'es_vigente',
        'subcategoria',
        'desc_subcat',
        'grupo',
        'desc_grup',
        'subgrupo',
        'desc_subg',
        'categoria',
        'desc_cat',
        'capitulo',
        'desc_cap',
        'tipo_serv',
        'pbs',
    ];

    protected $casts = [
        'es_vigente' => 'boolean',
    ];

    public const RESOLUCIONES = ['2077', '2336', '2641'];

    public const RESOLUCION_VIGENTE = '2641';

    public function scopeVigente(Builder $query): Builder
    {
        return $query->where('es_vigente', true);
    }

    public function scopeDeResolucion(Builder $query, string $resolucion): Builder
    {
        return $query->where('resolucion', $resolucion);
    }

    /**
     * Búsqueda por código o descripción.
     *
     * Usa el índice FULLTEXT sobre `desc_subcat` en lugar del `LIKE '%...%'`
     * del legacy, que forzaba escaneo completo de ~19.000 filas por consulta.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        // Códigos CUPS son numéricos: búsqueda por prefijo usa el índice.
        if (ctype_digit($termino)) {
            return $query->where('subcategoria', 'like', "{$termino}%");
        }

        if (mb_strlen($termino) >= 3) {
            return $query->whereFullText('desc_subcat', $termino);
        }

        return $query->where('desc_subcat', 'like', "{$termino}%");
    }
}
