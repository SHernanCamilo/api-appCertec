<?php

declare(strict_types=1);

namespace App\Models\Inventory\Ropa;

use App\Models\Config\ConfigUnidadFuncional;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RopaConstante extends Model
{
    protected $table = 'inv_ropa_constantes';

    protected $fillable = [
        'id_prenda',
        'id_unidad_funcional',
        'constante',
        'estado',
        'id_usuario',
    ];

    protected $casts = [
        'id_prenda' => 'integer',
        'id_unidad_funcional' => 'integer',
        'constante' => 'decimal:2',
        'estado' => 'integer',
        'id_usuario' => 'integer',
    ];

    public function prenda(): BelongsTo
    {
        return $this->belongsTo(RopaPrenda::class, 'id_prenda');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(ConfigUnidadFuncional::class, 'id_unidad_funcional');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', 1);
    }
}
