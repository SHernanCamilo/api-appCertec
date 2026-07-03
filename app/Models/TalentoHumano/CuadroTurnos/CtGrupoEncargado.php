<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class CtGrupoEncargado extends Model
{
    protected $table = 'humtal_ct_grupo_encargado';

    protected $fillable = [
        'id_grupo',
        'id_user',
        'fecha_inicio',
        'fecha_fin',
        'motivo_cambio',
        'registrado_por',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CtGrupo::class, 'id_grupo');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Encargados activos (sin fecha de fin).
     */
    public function scopeActivos($query)
    {
        return $query->whereNull('fecha_fin');
    }

    /**
     * Encargados históricos (con fecha de fin).
     */
    public function scopeHistorico($query)
    {
        return $query->whereNotNull('fecha_fin');
    }
}
