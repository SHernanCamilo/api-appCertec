<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BiVista extends Model
{
    protected $table = 'bi_vistas';

    /** Vista disponible para consultar */
    public const ESTADO_ACTIVO = 'activo';

    /** Vista en mantenimiento (no se muestra al usuario, pero existe) */
    public const ESTADO_MANTENIMIENTO = 'mantenimiento';

    /** Vista deshabilitada (no se muestra ni se consulta) */
    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'id_bi_grupos',
        'nombre',
        'descripcion',
        'departamentos',
        'estado',
    ];

    protected $casts = [
        'departamentos' => 'array',
    ];

    protected $attributes = [
        'estado' => self::ESTADO_ACTIVO,
    ];

    protected static function booted(): void
    {
        $clearCaches = function (): void {
            Cache::forget('bi_vistas_depto_config');
            Cache::forget('bi_vistas_estado_index');
        };

        static::saved($clearCaches);
        static::deleted($clearCaches);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivas($query)
    {
        return $query->where('estado', self::ESTADO_ACTIVO);
    }

    public function scopeNoInactivas($query)
    {
        return $query->where('estado', '!=', self::ESTADO_INACTIVO);
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(BiGrupo::class, 'id_bi_grupos');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * ¿Está activa para consultar?
     */
    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    /**
     * ¿Está en mantenimiento?
     */
    public function enMantenimiento(): bool
    {
        return $this->estado === self::ESTADO_MANTENIMIENTO;
    }

    /**
     * Extrae código de sede del departamento Azure (ej. "MA-TIC" → "MA", "NAL" → "NAL").
     */
    public static function extractSiteCode(?string $departamento): ?string
    {
        if ($departamento === null || trim($departamento) === '') {
            return null;
        }

        $parts = preg_split('/[-\s]+/', trim($departamento));

        return strtoupper($parts[0] ?? '') ?: null;
    }

    /**
     * Sin departamentos configurados → visible para todos.
     * Con lista → solo usuarios cuya sede esté incluida.
     */
    public function visibleParaDepartamento(?string $departamentoUsuario): bool
    {
        $permitidos = $this->departamentos;

        if ($permitidos === null || $permitidos === []) {
            return true;
        }

        $site = self::extractSiteCode($departamentoUsuario);
        if ($site === null) {
            return false;
        }

        $normalizados = array_map(
            fn ($d) => strtoupper(trim((string) $d)),
            $permitidos
        );

        return in_array($site, $normalizados, true);
    }
}
