<?php

namespace App\Models\Turnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Sede;
use App\Models\Empresa;

/**
 * Unidad Funcional (departamento/área) de la organización.
 * Tabla: config_unidades_funcionales
 *
 * Estructura:
 *   - id, codigo, nombre, id_empresa (FK ent_empresas), id_sede (FK config_ubi_sede), estado
 */
class ConfigUnidadFuncional extends Model
{
    protected $table = 'config_unidades_funcionales';

    protected $fillable = [
        'codigo',
        'nombre',
        'id_empresa',
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
