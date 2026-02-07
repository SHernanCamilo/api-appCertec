<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatzobsActivosC extends Model
{
    use HasFactory;

    protected $table = 'matzobs_activos_c';
    
    protected $fillable = [
        'id_activo_glpi',
        'nombre_equipo',
        'id_empresa',
        'id_sede', 
        'id_sucursal',
        'agente',
        'placa',
        'serial',
        'ubicacion',
        'puntaje',
        'usuario_modificacion',
        'date_u_sincronizacion'
    ];

    protected $casts = [
        'date_u_sincronizacion' => 'datetime',
        'fecha_compra' => 'date',
        'puntaje' => 'decimal:2'
    ];

    /**
     * Relación con detalles técnicos del activo
     */
    public function detalles()
    {
        return $this->hasOne(MatzobsActivosD::class, 'activo_c_id'); // Usar activo_c_id
    }

    /**
     * Scope para activos por empresa
     */
    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    /**
     * Scope para activos por sede
     */
    public function scopePorSede($query, $sedeId)
    {
        return $query->where('id_sede', $sedeId);
    }

    /**
     * Scope para activos por sucursal
     */
    public function scopePorSucursal($query, $sucursalId)
    {
        return $query->where('id_sucursal', $sucursalId);
    }

    /**
     * Scope para buscar por ID de GLPI
     */
    public function scopePorGlpiId($query, $glpiId)
    {
        return $query->where('id_activo_glpi', $glpiId);
    }

    /**
     * Scope para activos sincronizados recientemente
     */
    public function scopeSincronizadosReciente($query, $horas = 24)
    {
        return $query->where('date_u_sincronizacion', '>=', now()->subHours($horas));
    }

    /**
     * Scope para buscar por agente
     */
    public function scopePorAgente($query, $agente)
    {
        return $query->where('agente', $agente);
    }

    /**
     * Accessor para verificar si fue sincronizado hoy
     */
    public function getSincronizadoHoyAttribute()
    {
        return $this->date_u_sincronizacion && $this->date_u_sincronizacion->isToday();
    }

    /**
     * Accessor para obtener el puntaje formateado
     */
    public function getPuntajeFormateadoAttribute()
    {
        return number_format($this->puntaje, 2) . '%';
    }
}