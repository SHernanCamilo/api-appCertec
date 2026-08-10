<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Empleado;
use App\Models\User;
use App\Models\Sede;

class AntiSolicitud extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'anti_solicitudes';

    // Estados del flujo
    const ESTADO_BORRADOR               = 'borrador';
    const ESTADO_PENDIENTE_JEFE         = 'pendiente_jefe';
    const ESTADO_RECHAZADO_JEFE         = 'rechazado_jefe';
    const ESTADO_PENDIENTE_FINANCIERO   = 'pendiente_financiero';
    const ESTADO_RECHAZADO_FINANCIERO   = 'rechazado_financiero';
    const ESTADO_AUTORIZADO             = 'autorizado';
    const ESTADO_EN_VIAJE               = 'en_viaje';
    const ESTADO_PENDIENTE_LEGALIZACION = 'pendiente_legalizacion';
    const ESTADO_LEGALIZADO             = 'legalizado';
    const ESTADO_PENDIENTE_REINTEGRO    = 'pendiente_reintegro';
    const ESTADO_REINTEGRADO            = 'reintegrado';
    const ESTADO_PENDIENTE_EXCEDENTE    = 'pendiente_excedente';
    const ESTADO_APROBADO_EXCEDENTE     = 'aprobado_excedente';
    const ESTADO_RECHAZADO_EXCEDENTE    = 'rechazado_excedente';
    const ESTADO_CERRADO                = 'cerrado';

    protected $fillable = [
        'numero_solicitud',
        'id_empleado',
        'unidad_funcional',
        'id_sede_origen',
        'id_ciudad_destino',
        'fecha_salida',
        'fecha_regreso',
        'motivo',
        'cobertura',
        'monto_solicitado',
        'monto_autorizado',
        'monto_legalizado',
        'monto_reintegro',
        'monto_excedente',
        'estado',
        'radicado_por',
        'observaciones',
    ];

    protected $casts = [
        'fecha_salida'       => 'date',
        'fecha_regreso'      => 'date',
        'monto_solicitado'   => 'decimal:2',
        'monto_autorizado'   => 'decimal:2',
        'monto_legalizado'   => 'decimal:2',
        'monto_reintegro'    => 'decimal:2',
        'monto_excedente'    => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function sedeOrigen()
    {
        return $this->belongsTo(Sede::class, 'id_sede_origen');
    }

    public function ciudadDestino()
    {
        return $this->belongsTo(AntiCiudad::class, 'id_ciudad_destino');
    }

    public function radicador()
    {
        return $this->belongsTo(User::class, 'radicado_por');
    }

    public function items()
    {
        return $this->hasMany(AntiSolicitudItem::class, 'id_solicitud');
    }

    public function aprobaciones()
    {
        return $this->hasMany(AntiSolicitudAprobacion::class, 'id_solicitud')->orderBy('created_at');
    }

    public function documentos()
    {
        return $this->hasMany(AntiSolicitudDocumento::class, 'id_solicitud');
    }

    // Helpers de estado
    public function estaEnEstado(string $estado): bool
    {
        return $this->estado === $estado;
    }

    public function tieneSobrante(): bool
    {
        return $this->monto_reintegro !== null && $this->monto_reintegro > 0;
    }

    public function tieneExcedente(): bool
    {
        return $this->monto_excedente !== null && $this->monto_excedente > 0;
    }
}
