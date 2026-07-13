<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiVistaAccessLog extends Model
{
    public const ACCION_CONSULTA             = 'consulta';
    public const ACCION_EXPORT_INICIO        = 'exportacion_inicio';
    public const ACCION_EXPORT_DESCARGA      = 'exportacion_descarga';
    public const ACCION_EXPORT_SYNC          = 'exportacion_sync';

    public $timestamps = false;

    protected $table = 'bi_vista_access_logs';

    protected $fillable = [
        'user_id',
        'user_email',
        'user_name',
        'empresa_id',
        'empresa_nombre',
        'schema_name',
        'view_name',
        'accion',
        'filters',
        'rows_returned',
        'elapsed_ms',
        'success',
        'ip_address',
        'user_agent',
        'metadata',
        'accessed_at',
    ];

    protected $casts = [
        'filters'     => 'array',
        'metadata'    => 'array',
        'success'     => 'boolean',
        'accessed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
