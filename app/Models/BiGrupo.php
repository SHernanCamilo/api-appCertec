<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiGrupo extends Model
{
    /** Reportes asistenciales (BI-VISTAS-REPORTE_AS) */
    public const TIPO_ASISTENCIAL = 1;

    /** Reportes financieros (BI-VISTAS-REPORTE_FI) */
    public const TIPO_FINANCIERO = 2;

    /** Reportes administrativos (BI-VISTAS-REPORTE_AD) */
    public const TIPO_ADMINISTRATIVO = 3;

    /** @deprecated Use TIPO_ASISTENCIAL */
    public const TIPO_ESQUEMA = self::TIPO_ASISTENCIAL;

    /** @deprecated Use TIPO_FINANCIERO */
    public const TIPO_DEPARTAMENTO = self::TIPO_FINANCIERO;

    protected $table = 'bi_grupos';

    protected $fillable = [
        'codigo',
        'tipo',
        'descripcion',
        'usuario_crea_id',
        'usuario_modifica_id',
    ];

    protected $casts = [
        'tipo' => 'integer',
    ];

    public function usuarioCrea(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_crea_id');
    }

    public function usuarioModifica(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_modifica_id');
    }
}
