<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot del estado de un parquet en un momento dado.
 * Trazabilidad de la generación de parquets por Graph-Fabric.
 */
class BiParquetHistory extends Model
{
    protected $table = 'bi_parquet_history';

    public $timestamps = false;

    protected $fillable = [
        'schema_name',
        'view_name',
        'status',
        'lane',
        'age_hours',
        'avg_generation_s',
        'size_mb',
        'row_count',
        'is_stale_by_config',
        'error_message',
        'captured_at',
    ];

    protected $casts = [
        'age_hours'          => 'float',
        'avg_generation_s'   => 'float',
        'size_mb'            => 'float',
        'row_count'          => 'integer',
        'is_stale_by_config' => 'boolean',
        'captured_at'        => 'datetime',
    ];

    public function scopeForView($query, string $schema, string $view)
    {
        return $query->where('schema_name', $schema)->where('view_name', $view);
    }

    public function scopeErrors($query)
    {
        return $query->where('status', 'error');
    }
}
