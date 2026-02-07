<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class MatrizObsActivoC extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'matzobs_activos_c';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id_activo_glpi',
        'id_empresa',
        'id_sucursal',
        'id_sede',
        'nombre_equipo',
        'agente',
        'placa',
        'serial',
        'ubicacion',
        'puntaje',
        'usuario_modificacion',
        'date_u_sincronizacion',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id_empresa' => 'integer',
        'id_sucursal' => 'integer',
        'id_sede' => 'integer',
        'puntaje' => 'decimal:2',
        'date_u_sincronizacion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación uno a uno con la tabla detalle
     */
    public function detalle(): HasOne
    {
        return $this->hasOne(MatrizObsActivoD::class, 'activo_c_id');
    }

    /**
     * Relación con Sucursal
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    /**
     * Relación con Sede
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    /**
     * Relación con Empresa
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    /**
     * Scope para buscar por ID de activo GLPI
     */
    public function scopeByGlpiId($query, $glpiId)
    {
        return $query->where('id_activo_glpi', $glpiId);
    }

    /**
     * Scope para buscar por sucursal
     */
    public function scopeBySucursal($query, $sucursalId)
    {
        return $query->where('id_sucursal', $sucursalId);
    }

    /**
     * Scope para buscar por sede
     */
    public function scopeBySede($query, $sedeId)
    {
        return $query->where('id_sede', $sedeId);
    }

    /**
     * Scope para buscar por empresa
     */
    public function scopeByEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    /**
     * Scope para buscar por rango de puntaje
     */
    public function scopeByPuntajeRange($query, $min, $max)
    {
        return $query->whereBetween('puntaje', [$min, $max]);
    }

    /**
     * Scope para activos obsoletos (puntaje bajo)
     */
    public function scopeObsoletos($query, $threshold = 40)
    {
        return $query->where('puntaje', '<', $threshold);
    }

    /**
     * Scope para activos óptimos (puntaje alto)
     */
    public function scopeOptimos($query, $threshold = 80)
    {
        return $query->where('puntaje', '>=', $threshold);
    }

    /**
     * Obtener el estado del activo basado en el puntaje
     */
    public function getEstadoAttribute(): string
    {
        if ($this->puntaje >= 80) {
            return 'Óptimo';
        } elseif ($this->puntaje >= 60) {
            return 'Funcional';
        } elseif ($this->puntaje >= 40) {
            return 'Potencialmente Obsoleto';
        } else {
            return 'Obsoleto';
        }
    }

    /**
     * Obtener el color del estado
     */
    public function getColorEstadoAttribute(): string
    {
        if ($this->puntaje >= 80) {
            return '#198754'; // Verde
        } elseif ($this->puntaje >= 60) {
            return '#0dcaf0'; // Azul
        } elseif ($this->puntaje >= 40) {
            return '#ffc107'; // Amarillo
        } else {
            return '#dc3545'; // Rojo
        }
    }

    /**
     * Obtener información completa del activo con detalle
     */
    public function getInfoCompleta()
    {
        return $this->load('detalle');
    }
}