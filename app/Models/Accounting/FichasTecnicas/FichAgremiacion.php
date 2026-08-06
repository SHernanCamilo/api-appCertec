<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agremiación / prestador de servicios de salud contratado.
 *
 * @property int         $id
 * @property string      $nombre
 * @property string|null $nit
 * @property string|null $rep_legal
 * @property string|null $cc_rep_legal
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $correo
 * @property bool        $estado
 */
class FichAgremiacion extends Model
{
    protected $table = 'fich_agremiaciones';

    protected $fillable = [
        'nombre',
        'nit',
        'rep_legal',
        'cc_rep_legal',
        'direccion',
        'telefono',
        'correo',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function fichas(): HasMany
    {
        return $this->hasMany(FichFicha::class, 'id_agremiacion');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', true);
    }

    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino): void {
            $q->where('nombre', 'like', "%{$termino}%")
                ->orWhere('nit', 'like', "{$termino}%");
        });
    }
}
