<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado guardado de un workbook del visor BI Excel.
 *
 * Almacena la configuracion completa del workspace del usuario:
 * - Hojas abiertas (con sus datos y columnas)
 * - Filtros aplicados
 * - Orden y ancho de columnas
 * - Configuracion de pivot tables
 * - Zoom y preferencias de visualizacion
 */
class BiWorkbookState extends Model
{
    protected $table = 'bi_workbook_states';

    protected $fillable = [
        'user_id',
        'schema_name',
        'view_name',
        'name',
        'state',
        'is_default',
    ];

    protected $casts = [
        'state'      => 'array',
        'is_default' => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForView($query, string $schema, string $viewName)
    {
        return $query->where('schema_name', $schema)->where('view_name', $viewName);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
