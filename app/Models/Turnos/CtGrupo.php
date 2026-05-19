<?php

namespace App\Models\Turnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Empresa;
use App\Models\Sede;

class CtGrupo extends Model
{
    protected $table = 'humtal_ct_grupos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'id_empresa',
        'id_sede',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    /**
     * Historial completo de encargados.
     */
    public function encargados(): HasMany
    {
        return $this->hasMany(CtGrupoEncargado::class, 'id_grupo')->orderByDesc('fecha_inicio');
    }

    /**
     * Encargado activo actual (fecha_fin IS NULL).
     */
    public function encargadoActual(): HasOne
    {
        return $this->hasOne(CtGrupoEncargado::class, 'id_grupo')
                    ->whereNull('fecha_fin');
    }

    /**
     * Todos los registros de empleados (activos e históricos).
     */
    public function empleados(): HasMany
    {
        return $this->hasMany(CtGrupoEmpleado::class, 'id_grupo');
    }

    /**
     * Cuadros de turno del grupo.
     */
    public function cuadros(): HasMany
    {
        return $this->hasMany(CtCuadro::class, 'id_grupo')->orderByDesc('anio')->orderByDesc('mes');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivos($query)
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
