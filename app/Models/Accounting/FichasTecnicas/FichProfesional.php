<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Profesional de la salud vinculado a fichas técnicas.
 *
 * @property int         $id
 * @property string      $documento
 * @property string      $nombre
 * @property string|null $tarjeta_profesional
 * @property string|null $correo
 * @property string|null $telefono
 * @property bool        $estado
 */
class FichProfesional extends Model
{
    protected $table = 'fich_profesionales';

    protected $fillable = [
        'documento',
        'nombre',
        'tarjeta_profesional',
        'correo',
        'telefono',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function especialidades(): BelongsToMany
    {
        return $this->belongsToMany(
            FichEspecialidad::class,
            'fich_profesional_especialidad',
            'id_profesional',
            'id_especialidad'
        )->withTimestamps();
    }

    public function fichas(): BelongsToMany
    {
        return $this->belongsToMany(
            FichFicha::class,
            'fich_ficha_profesional',
            'id_profesional',
            'id_ficha'
        )->withPivot('novedad')->withTimestamps();
    }

    public function scopeActivos(Builder $query): Builder
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
                ->orWhere('documento', 'like', "{$termino}%")
                ->orWhere('tarjeta_profesional', 'like', "{$termino}%");
        });
    }

    /** Filtra profesionales que atienden una especialidad concreta. */
    public function scopeDeEspecialidad(Builder $query, int $idEspecialidad): Builder
    {
        return $query->whereHas(
            'especialidades',
            fn (Builder $q): Builder => $q->where('fich_especialidades.id', $idEspecialidad)
        );
    }
}
