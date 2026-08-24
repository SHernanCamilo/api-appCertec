<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuracion de refresh de parquets por vista.
 *
 * Controla cada cuanto Graph-Fabric regenera el parquet de cada vista
 * y con que prioridad. Laravel sincroniza esta tabla con el scheduler
 * de Graph-Fabric via POST /api/r2/schedule.
 */
class BiParquetConfig extends Model
{
    protected $table = 'bi_parquet_config';

    protected $fillable = [
        'schema_name',
        'view_name',
        'refresh_interval_min',
        'priority',
        'group_name',
        'enabled',
        'last_synced_at',
        'estimated_rows',
    ];

    protected $casts = [
        'refresh_interval_min' => 'integer',
        'enabled'              => 'boolean',
        'last_synced_at'       => 'datetime',
        'estimated_rows'       => 'integer',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeForView($query, string $schema, string $view)
    {
        return $query->where('schema_name', $schema)->where('view_name', $view);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Intervalo de refresh en formato legible.
     */
    public function intervalLabel(): string
    {
        $min = $this->refresh_interval_min;
        if ($min < 60) return "{$min} min";
        $hours = $min / 60;
        return $hours == 1 ? '1 hora' : "{$hours} horas";
    }

    /**
     * Si el parquet se considera stale segun su intervalo configurado.
     */
    public function isStale(?int $ageMinutes): bool
    {
        if ($ageMinutes === null) return true;
        return $ageMinutes > $this->refresh_interval_min;
    }

    /**
     * Nombre calificado de la vista (schema.view).
     */
    public function qualifiedName(): string
    {
        return "{$this->schema_name}.{$this->view_name}";
    }
}
