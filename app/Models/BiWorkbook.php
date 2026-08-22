<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un "Excel guardado" del visor BI.
 *
 * Representa un workbook que puede contener multiples vistas cargadas como
 * hojas (maximo 5), mas hojas de analisis (formulas, pivots). El usuario
 * lo abre y recupera todo como lo dejo: vistas, hojas de calculo, filtros,
 * formatos y formulas.
 */
class BiWorkbook extends Model
{
    protected $table = 'bi_workbooks';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'views',
        'state',
        'is_favorite',
        'last_opened_at',
    ];

    protected $casts = [
        'views'          => 'array',
        'state'          => 'array',
        'is_favorite'    => 'boolean',
        'last_opened_at' => 'datetime',
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

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Cuantas vistas de datos contiene este workbook.
     */
    public function viewCount(): int
    {
        return is_array($this->views) ? count($this->views) : 0;
    }

    /**
     * Nombres de vistas (para mostrar en tarjetas).
     */
    public function viewNames(): array
    {
        if (!is_array($this->views)) return [];
        return array_map(fn($v) => $v['label'] ?? $v['viewName'] ?? 'Sin nombre', $this->views);
    }
}
