<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ConfigPersonTercero;

class CtFrecuencia extends Model
{
    protected $table = 'humtal_ct_frecuencia';

    const TIPO_SIN_PROGRAMACION = 'sin_programacion';
    const TIPO_POR_NUMERO_DIAS  = 'por_numero_dias';
    const TIPO_POR_DIAS_SEMANA  = 'por_dias_semana';
    const TIPO_DIAS_DEL_MES     = 'dias_del_mes';

    protected $fillable = [
        'id_empleado', 'id_plantilla', 'id_cuadro', 'tipo_frecuencia',
        'cada_n_dias', 'dias_semana', 'dias_mes',
        'fecha_inicio', 'fecha_fin',
        'incluir_festivos', 'incluir_dominicales', 'es_descanso',
        'hora_inicio_override', 'hora_fin_override',
        'observacion', 'estado', 'creado_por',
    ];

    protected $casts = [
        'fecha_inicio'        => 'date',
        'fecha_fin'           => 'date',
        'dias_semana'         => 'array',
        'dias_mes'            => 'array',
        'incluir_festivos'    => 'boolean',
        'incluir_dominicales' => 'boolean',
        'es_descanso'         => 'boolean',
        'estado'              => 'boolean',
        'cada_n_dias'         => 'integer',
    ];

    public function empleado(): BelongsTo { return $this->belongsTo(ConfigPersonTercero::class, 'id_empleado'); }
    public function plantilla(): BelongsTo { return $this->belongsTo(CtPlantilla::class, 'id_plantilla'); }
    public function cuadro(): BelongsTo { return $this->belongsTo(CtCuadro::class, 'id_cuadro'); }

    public function scopeActivas($query) { return $query->where('estado', true); }
    public function scopePorEmpleado($query, int $id) { return $query->where('id_empleado', $id); }
    public function scopePorTipo($query, string $tipo) { return $query->where('tipo_frecuencia', $tipo); }

    public function tieneProgramacion(): bool { return $this->tipo_frecuencia !== self::TIPO_SIN_PROGRAMACION; }

    public static function tiposDisponibles(): array
    {
        return [
            self::TIPO_SIN_PROGRAMACION => 'Sin programaci+¦n',
            self::TIPO_POR_NUMERO_DIAS  => 'Por n+¦mero de d+¡as',
            self::TIPO_POR_DIAS_SEMANA  => 'Por d+¡as de la semana',
            self::TIPO_DIAS_DEL_MES     => 'D+¡as del mes',
        ];
    }
}
