<?php

namespace App\Models\Turnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Sede;
use App\Models\Empresa;
use App\Models\Sucursal;

class ConfigUnidadFuncional extends Model
{
    protected $table = 'config_unidades_funcionales';

    protected $fillable = [
        'codigo',
        'nombre',
        'id_empresa',
        'id_sucursal',
        'id_sede',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    public function scopeActivas($query)
    {
        return $query->where('estado', true);
    }

    public function scopePorEmpresa($query, int $idEmpresa)
    {
        return $query->where('id_empresa', $idEmpresa);
    }

    public function scopePorSede($query, int $idSede)
    {
        return $query->where('id_sede', $idSede);
    }
}
