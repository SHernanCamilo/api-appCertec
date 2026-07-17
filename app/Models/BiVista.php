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

    public function usuariosConPermisoOData()
    {
        return $this->belongsToMany(\App\Models\User::class, 'bi_vista_user_permissions', 'bi_vista_id', 'user_id');
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
        $site = self::extractSiteCode($departamentoUsuario);

        return $this->visibleParaSiteCodes(
            $site !== null ? [$site] : [],
            in_array($site, ['NAL', 'NAC', 'MA'], true)
        );
    }

    /**
     * Visible si la vista no restringe departamentos, o si alguno de los
     * site_codes del usuario (o nacional) está permitido.
     *
     * @param  string[]  $siteCodes
     */
    public function visibleParaSiteCodes(array $siteCodes, bool $isNational = false): bool
    {
        $permitidos = $this->departamentos;

        if ($permitidos === null || $permitidos === []) {
            return true;
        }

        $normalizados = array_map(
            fn ($d) => strtoupper(trim((string) $d)),
            $permitidos
        );

        if ($isNational) {
            return in_array('NAL', $normalizados, true)
                || in_array('NAC', $normalizados, true)
                || in_array('MA', $normalizados, true);
        }

        foreach ($siteCodes as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '' && in_array($code, $normalizados, true)) {
                return true;
            }
        }

        return false;
    }
}
