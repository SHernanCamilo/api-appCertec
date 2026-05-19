<?php

namespace App\Models\Turnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ConfigPersonTercero;

class CtGrupoEmpleado extends Model
{
    protected $table = 'humtal_ct_grupo_empleado';

    protected $fillable = [
        'id_grupo',
        'id_empleado',
        'fecha_ingreso',
        'fecha_salida',
        'estado',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_salida'  => 'date',
        'estado'        => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CtGrupo::class, 'id_grupo');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(ConfigPersonTercero::class, 'id_empleado');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Empleados activos en el grupo (sin fecha de salida y estado activo).
     */
    public function scopeActivos($query)
    {
        return $query->whereNull('fecha_salida')->where('estado', true);
    }

    /**
     * Empleados activos en una fecha específica.
     * Activo = ingresó antes o en esa fecha y no ha salido (o salió después).
     */
    public function scopeEnFecha($query, string $fecha)
    {
        return $query->where('estado', true)
                     ->where('fecha_ingreso', '<=', $fecha)
                     ->where(function ($q) use ($fecha) {
                         $q->whereNull('fecha_salida')
                           ->orWhere('fecha_salida', '>=', $fecha);
                     });
    }
}
