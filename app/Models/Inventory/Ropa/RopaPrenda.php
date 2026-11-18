<?php

declare(strict_types=1);

namespace App\Models\Inventory\Ropa;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RopaPrenda extends Model
{
    protected $table = 'inv_ropa_prendas';

    protected $fillable = [
        'codigo',
        'nombre',
        'regla',
        'estado',
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function constantes(): HasMany
    {
        return $this->hasMany(RopaConstante::class, 'id_prenda');
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', 1);
    }

    public function reglaNombre(): string
    {
        return match ($this->regla) {
            'estar_medico' => 'Estar médico',
            'personal' => 'Personal',
            default => 'Cama',
        };
    }
}
