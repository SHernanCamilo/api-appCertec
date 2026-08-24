<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de Inventario — define las reglas de periodicidad para la toma de inventario.
 *
 * Ejemplos:
 *   - Inventario General: anual (máximo 1 registro por activo al año)
 *   - Inventario Aleatorio: mensual (máximo 1 registro por activo al mes)
 *
 * @property int $id
 * @property string $nombre
 * @property string $periodicidad (anual|mensual|semestral|trimestral|semanal|ninguna)
 * @property bool $activo
 * @property string|null $descripcion
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TipoInventario extends Model
{
    use HasFactory;

    protected $table = 'inv_tipos_inventario';

    protected $fillable = [
        'nombre',
        'periodicidad',
        'activo',
        'descripcion',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con trazabilidad de activos
     */
    public function trazabilidades(): HasMany
    {
        return $this->hasMany(TrazabilidadActivo::class, 'tipo_inventario_id');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Scope: solo tipos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Scope: solo tipos inactivos
     */
    public function scopeInactivos($query)
    {
        return $query->where('activo', false);
    }

    /**
     * Scope: filtrar por periodicidad
     */
    public function scopePorPeriodicidad($query, string $periodicidad)
    {
        return $query->where('periodicidad', $periodicidad);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MÉTODOS DE INSTANCIA
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Activa el tipo de inventario
     */
    public function activar(): bool
    {
        $this->activo = true;
        return $this->save();
    }

    /**
     * Desactiva el tipo de inventario
     */
    public function desactivar(): bool
    {
        $this->activo = false;
        return $this->save();
    }

    /**
     * Alterna el estado activo/inactivo
     */
    public function toggleEstado(): bool
    {
        $this->activo = !$this->activo;
        return $this->save();
    }

    /**
     * Verifica si un activo puede registrarse con este tipo según la periodicidad
     *
     * @param string $placa Placa del activo
     * @param \Carbon\Carbon|null $fecha Fecha de referencia (default: hoy)
     * @return bool True si puede registrarse, False si ya existe un registro en el período
     */
    public function puedeRegistrarActivo(string $placa, ?\Carbon\Carbon $fecha = null): bool
    {
        $fecha = $fecha ?? now();

        // Si no hay validación de periodicidad, siempre puede registrarse
        if ($this->periodicidad === 'ninguna') {
            return true;
        }

        // Calcular el rango de fechas según la periodicidad
        [$desde, $hasta] = $this->calcularRangoPeriodicidad($fecha);

        // Verificar si existe un registro en ese rango
        $existe = TrazabilidadActivo::where('placa', $placa)
            ->where('tipo_inventario_id', $this->id)
            ->whereBetween('created_at', [$desde, $hasta])
            ->exists();

        return !$existe;
    }

    /**
     * Calcula el rango de fechas según la periodicidad
     *
     * @param \Carbon\Carbon $fecha
     * @return array [\Carbon\Carbon $desde, \Carbon\Carbon $hasta]
     */
    public function calcularRangoPeriodicidad(\Carbon\Carbon $fecha): array
    {
        return match ($this->periodicidad) {
            'anual' => [
                $fecha->copy()->startOfYear(),
                $fecha->copy()->endOfYear(),
            ],
            'semestral' => [
                $fecha->copy()->startOfQuarter()->subMonths($fecha->quarter % 2 === 0 ? 3 : 0),
                $fecha->copy()->endOfQuarter()->addMonths($fecha->quarter % 2 === 0 ? 0 : 3),
            ],
            'trimestral' => [
                $fecha->copy()->startOfQuarter(),
                $fecha->copy()->endOfQuarter(),
            ],
            'mensual' => [
                $fecha->copy()->startOfMonth(),
                $fecha->copy()->endOfMonth(),
            ],
            'semanal' => [
                $fecha->copy()->startOfWeek(),
                $fecha->copy()->endOfWeek(),
            ],
            default => [
                $fecha->copy()->startOfDay(),
                $fecha->copy()->endOfDay(),
            ],
        };
    }

    /**
     * Obtiene el nombre legible de la periodicidad
     */
    public function getPeriodicidadNombreAttribute(): string
    {
        return match ($this->periodicidad) {
            'anual' => 'Anual',
            'semestral' => 'Semestral',
            'trimestral' => 'Trimestral',
            'mensual' => 'Mensual',
            'semanal' => 'Semanal',
            'ninguna' => 'Sin restricción',
            default => $this->periodicidad,
        };
    }

    /**
     * Obtiene la descripción de la restricción
     */
    public function getDescripcionRestriccionAttribute(): string
    {
        return match ($this->periodicidad) {
            'anual' => 'Máximo 1 registro por activo al año',
            'semestral' => 'Máximo 1 registro por activo cada 6 meses',
            'trimestral' => 'Máximo 1 registro por activo cada 3 meses',
            'mensual' => 'Máximo 1 registro por activo al mes',
            'semanal' => 'Máximo 1 registro por activo a la semana',
            'ninguna' => 'Sin restricción de frecuencia',
            default => 'Periodicidad personalizada',
        };
    }
}
