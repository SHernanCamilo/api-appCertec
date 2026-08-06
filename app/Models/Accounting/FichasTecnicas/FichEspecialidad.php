<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Especialidad médica contratable.
 *
 * @property int         $id
 * @property string      $descripcion
 * @property string|null $perfil
 * @property bool        $estado
 */
class FichEspecialidad extends Model
{
    protected $table = 'fich_especialidades';

    protected $fillable = [
        'descripcion',
        'perfil',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    /** Perfiles válidos según el formulario legacy `parametrizador/new_esp.php`. */
    public const PERFILES = [
        'ANESTESIA',
        'CIRUJANO',
        'FISIATRA',
        'GENETISTA',
        'INSTRUMENTADOR',
        'PERFUSIONISTA',
        'ODONTOLOGO',
        'TERAPEUTA',
    ];

    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(
            FichProfesional::class,
            'fich_profesional_especialidad',
            'id_especialidad',
            'id_profesional'
        )->withTimestamps();
    }

    public function fichas(): HasMany
    {
        return $this->hasMany(FichFicha::class, 'id_especialidad');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', true);
    }
}
