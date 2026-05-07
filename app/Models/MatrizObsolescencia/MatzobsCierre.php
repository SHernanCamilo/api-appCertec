<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

/**
 * Modelo: MatzobsCierre
 *
 * Representa la cabecera de un cierre de inventario de la Matriz de Obsolescencia.
 * Cada cierre es una fotografía inmutable del estado de los activos en un momento dado.
 *
 * @property int         $id
 * @property string      $nombre
 * @property string|null $periodo
 * @property string|null $descripcion
 * @property string      $estado              pendiente|procesando|cerrado|error
 * @property \Carbon\Carbon|null $fecha_inicio_proceso
 * @property \Carbon\Carbon|null $fecha_fin_proceso
 * @property int|null    $duracion_segundos
 * @property string|null $mensaje_error
 * @property int         $total_activos
 * @property int         $total_optimo
 * @property int         $total_funcional
 * @property int         $total_potencial
 * @property int         $total_obsoleto
 * @property float       $puntaje_promedio
 * @property bool        $config_recalculo_aplicado
 * @property bool        $config_incluyo_sin_puntaje
 * @property bool        $config_incluyo_inactivos
 * @property int|null    $creado_por
 * @property string|null $nombre_creador
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MatzobsCierre extends Model
{
    use HasFactory;

    protected $table = 'matzobs_cierres';

    protected $fillable = [
        'nombre',
        'periodo',
        'descripcion',
        'estado',
        'fecha_inicio_proceso',
        'fecha_fin_proceso',
        'duracion_segundos',
        'mensaje_error',
        'total_activos',
        'total_optimo',
        'total_funcional',
        'total_potencial',
        'total_obsoleto',
        'puntaje_promedio',
        'config_recalculo_aplicado',
        'config_incluyo_sin_puntaje',
        'config_incluyo_inactivos',
        'creado_por',
        'nombre_creador',
    ];

    protected $casts = [
        'fecha_inicio_proceso'       => 'datetime',
        'fecha_fin_proceso'          => 'datetime',
        'duracion_segundos'          => 'integer',
        'total_activos'              => 'integer',
        'total_optimo'               => 'integer',
        'total_funcional'            => 'integer',
        'total_potencial'            => 'integer',
        'total_obsoleto'             => 'integer',
        'puntaje_promedio'           => 'decimal:2',
        'config_recalculo_aplicado'  => 'boolean',
        'config_incluyo_sin_puntaje' => 'boolean',
        'config_incluyo_inactivos'   => 'boolean',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────────────

    /**
     * Líneas de detalle (snapshot de activos) de este cierre.
     */
    public function detalles(): HasMany
    {
        return $this->hasMany(MatzobsCierreDetalle::class, 'cierre_id');
    }

    /**
     * Usuario que creó el cierre.
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeCerrados($query)
    {
        return $query->where('estado', 'cerrado');
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopePorPeriodo($query, string $periodo)
    {
        return $query->where('periodo', $periodo);
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    /**
     * Porcentaje de activos óptimos sobre el total.
     */
    public function getPorcentajeOptimoAttribute(): float
    {
        if ($this->total_activos === 0) return 0.0;
        return round(($this->total_optimo / $this->total_activos) * 100, 1);
    }

    /**
     * Porcentaje de activos obsoletos sobre el total.
     */
    public function getPorcentajeObsoletoAttribute(): float
    {
        if ($this->total_activos === 0) return 0.0;
        return round(($this->total_obsoleto / $this->total_activos) * 100, 1);
    }

    /**
     * Duración formateada (ej: "2m 34s").
     */
    public function getDuracionFormateadaAttribute(): string
    {
        if (!$this->duracion_segundos) return '-';
        $m = intdiv($this->duracion_segundos, 60);
        $s = $this->duracion_segundos % 60;
        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    }

    /**
     * Indica si el cierre está en progreso.
     */
    public function getEnProgresoAttribute(): bool
    {
        return $this->estado === 'procesando';
    }

    /**
     * Indica si el cierre finalizó correctamente.
     */
    public function getCerradoAttribute(): bool
    {
        return $this->estado === 'cerrado';
    }
}
