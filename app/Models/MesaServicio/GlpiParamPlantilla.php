<?php

declare(strict_types=1);

namespace App\Models\MesaServicio;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlpiParamPlantilla extends Model
{
    public const PRIORIDADES = ['baja', 'media', 'alta', 'muy_alta'];

    public const PRIORIDAD_LABELS = [
        'baja' => 'BAJA',
        'media' => 'MEDIA',
        'alta' => 'ALTA',
        'muy_alta' => 'MUY ALTA',
    ];

    public const UNIDADES = ['minuto', 'hora', 'dia'];

    protected $table = 'glpi_param_plantillas';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'id_empresa',
        'nombre_entidad',
        'grupo_tecnico',
        'sla_asignacion',
        'prefijo_regla',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'id_empresa' => 'integer',
        'created_by' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ans(): HasMany
    {
        return $this->hasMany(GlpiParamPlantillaAns::class, 'plantilla_id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(GlpiParamPlantillaCategoria::class, 'plantilla_id');
    }

    public function scopeActivas($query)
    {
        return $query->where('estado', true);
    }

    public static function nombreRegla(string $prioridad, string $prefijo): string
    {
        $label = self::PRIORIDAD_LABELS[$prioridad] ?? strtoupper($prioridad);
        $prefijo = trim($prefijo);

        return $prefijo === '' ? $label : "{$label} {$prefijo}";
    }

    /**
     * Resuelve el ANS de una categoría al nombre_regla vigente.
     * Cubre nombres viejos (ej. "MEDIA TIC") cuando la regla ahora es "MEDIA COMPRAS".
     */
    public static function resolverNombreAns(?string $ansNombre, ?string $prioridad, $ansList): ?string
    {
        $opciones = collect($ansList);
        if ($opciones->isEmpty()) {
            $actual = trim((string) $ansNombre);

            return $actual !== '' ? $actual : null;
        }

        $actual = trim((string) $ansNombre);
        if ($actual !== '') {
            $porNombre = $opciones->first(function ($ans) use ($actual): bool {
                foreach ([$ans->nombre_regla ?? '', $ans->nombre_sla_solucion ?? ''] as $candidato) {
                    if (self::normalizarNombreAns((string) $candidato) === self::normalizarNombreAns($actual)) {
                        return true;
                    }
                }

                return false;
            });
            if ($porNombre) {
                return trim((string) $porNombre->nombre_regla) ?: $actual;
            }
        }

        $prioridadEfectiva = self::prioridadDesdeNombreAns($actual) ?: trim((string) $prioridad);
        if ($prioridadEfectiva !== '' && in_array($prioridadEfectiva, self::PRIORIDADES, true)) {
            $porPrioridad = $opciones->first(
                fn ($ans) => (string) ($ans->prioridad ?? '') === $prioridadEfectiva
            );
            if ($porPrioridad && trim((string) $porPrioridad->nombre_regla) !== '') {
                return trim((string) $porPrioridad->nombre_regla);
            }
        }

        $primero = $opciones->first();
        if ($primero && trim((string) $primero->nombre_regla) !== '') {
            return trim((string) $primero->nombre_regla);
        }

        return $actual !== '' ? $actual : null;
    }

    public static function prioridadDesdeNombreAns(string $nombre): ?string
    {
        $n = self::normalizarNombreAns($nombre);
        if ($n === '') {
            return null;
        }
        if (str_starts_with($n, 'muy alta')) {
            return 'muy_alta';
        }
        if (str_starts_with($n, 'alta')) {
            return 'alta';
        }
        if (str_starts_with($n, 'media')) {
            return 'media';
        }
        if (str_starts_with($n, 'baja')) {
            return 'baja';
        }

        return null;
    }

    public static function normalizarNombreAns(string $valor): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $valor) ?? $valor);

        return mb_strtolower($texto);
    }
}
