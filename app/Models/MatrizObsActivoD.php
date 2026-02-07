<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatrizObsActivoD extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'matzobs_activos_d';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'activo_c_id',
        'marca',
        'tipo',
        'referencia',
        'tipo_unidad',
        'fecha_compra',
        'modalidad',
        'proveedor',
        'edad',
        'edad_v_util',
        'valoracion_edad',
        'tamano_ram',
        'generacion_ram',
        'valoracion_ram',
        'procesador',
        'numero_procesador',
        'valoracion_procesador',
        'tipo_disco',
        'tamano_disco',
        'interfaz_conexion',
        'valoracion_disco',
        'incidencias_6_meses',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'fecha_compra' => 'date',
        'edad' => 'integer',
        'edad_v_util' => 'integer',
        'valoracion_edad' => 'decimal:2',
        'tamano_ram' => 'decimal:2',
        'valoracion_ram' => 'decimal:2',
        'numero_procesador' => 'integer',
        'valoracion_procesador' => 'decimal:2',
        'tamano_disco' => 'decimal:2',
        'valoracion_disco' => 'decimal:2',
        'incidencias_6_meses' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con la tabla cabecera
     */
    public function activoC(): BelongsTo
    {
        return $this->belongsTo(MatrizObsActivoC::class, 'activo_c_id');
    }

    /**
     * Scope para buscar por marca
     */
    public function scopeByMarca($query, $marca)
    {
        return $query->where('marca', 'like', "%{$marca}%");
    }

    /**
     * Scope para buscar por tipo
     */
    public function scopeByTipo($query, $tipo)
    {
        return $query->where('tipo', 'like', "%{$tipo}%");
    }

    /**
     * Scope para buscar por generación de RAM
     */
    public function scopeByGeneracionRam($query, $generacion)
    {
        return $query->where('generacion_ram', $generacion);
    }

    /**
     * Scope para buscar por rango de edad
     */
    public function scopeByEdadRange($query, $min, $max)
    {
        return $query->whereBetween('edad', [$min, $max]);
    }

    /**
     * Scope para buscar por tamaño mínimo de RAM
     */
    public function scopeByMinRam($query, $minRam)
    {
        return $query->where('tamano_ram', '>=', $minRam);
    }

    /**
     * Scope para buscar por número mínimo de procesadores
     */
    public function scopeByMinProcesadores($query, $minProc)
    {
        return $query->where('numero_procesador', '>=', $minProc);
    }

    /**
     * Obtener el estado de la RAM basado en la valoración
     */
    public function getEstadoRamAttribute(): string
    {
        if ($this->valoracion_ram >= 80) {
            return 'Excelente';
        } elseif ($this->valoracion_ram >= 60) {
            return 'Bueno';
        } elseif ($this->valoracion_ram >= 40) {
            return 'Regular';
        } else {
            return 'Deficiente';
        }
    }

    /**
     * Obtener el estado del procesador basado en la valoración
     */
    public function getEstadoProcesadorAttribute(): string
    {
        if ($this->valoracion_procesador >= 80) {
            return 'Excelente';
        } elseif ($this->valoracion_procesador >= 60) {
            return 'Bueno';
        } elseif ($this->valoracion_procesador >= 40) {
            return 'Regular';
        } else {
            return 'Deficiente';
        }
    }

    /**
     * Obtener el estado del disco basado en la valoración
     */
    public function getEstadoDiscoAttribute(): string
    {
        if ($this->valoracion_disco >= 80) {
            return 'Excelente';
        } elseif ($this->valoracion_disco >= 60) {
            return 'Bueno';
        } elseif ($this->valoracion_disco >= 40) {
            return 'Regular';
        } else {
            return 'Deficiente';
        }
    }

    /**
     * Calcular el promedio de valoraciones
     */
    public function getPromedioValoracionesAttribute(): float
    {
        $valoraciones = collect([
            $this->valoracion_edad,
            $this->valoracion_ram,
            $this->valoracion_procesador,
            $this->valoracion_disco
        ])->filter()->values();

        return $valoraciones->isEmpty() ? 0 : $valoraciones->average();
    }

    /**
     * Verificar si el equipo necesita actualización
     */
    public function getNecesitaActualizacionAttribute(): bool
    {
        return $this->promedio_valoraciones < 60;
    }
}