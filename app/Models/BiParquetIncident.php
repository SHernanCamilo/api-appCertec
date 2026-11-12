<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Incidente de generación lenta de un parquet (> umbral, por defecto 5 min).
 */
class BiParquetIncident extends Model
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_RESOLVED = 'resolved';

    public const SOURCE_USER      = 'user_refresh';
    public const SOURCE_SCHEDULER = 'scheduler';

    /** Superó el tope de espera (5 min). */
    public const REASON_TIMEOUT = 'timeout';
    /** La generación falló (consulta a Fabric, conexión, etc.); el motivo va en `detail`. */
    public const REASON_ERROR   = 'error';

    protected $table = 'bi_parquet_incidents';

    protected $fillable = [
        'schema_name', 'view_name', 'source', 'reason', 'user_id', 'user_name',
        'elapsed_s', 'stage', 'status', 'detail', 'notified_at', 'resolved_at',
    ];

    protected $casts = [
        'elapsed_s'   => 'integer',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForView(Builder $query, string $schema, string $view): Builder
    {
        return $query->where('schema_name', strtolower($schema))->where('view_name', $view);
    }
}
