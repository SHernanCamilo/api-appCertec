<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BiVista extends Model
{
    protected $table = 'bi_vistas';

    protected $fillable = [
        'id_bi_grupos',
        'nombre',
        'descripcion',
        'departamentos',
    ];

    protected $casts = [
        'departamentos' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('bi_vistas_depto_config'));
        static::deleted(fn () => Cache::forget('bi_vistas_depto_config'));
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(BiGrupo::class, 'id_bi_grupos');
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
