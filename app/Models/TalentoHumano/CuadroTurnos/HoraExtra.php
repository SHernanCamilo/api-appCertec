<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;

class HoraExtra extends Model
{
    protected $table = 'humtal_horas_extras';

    protected $fillable = [
        'id_empleado', 'id_cuadro', 'fecha',
        'hora_inicio', 'hora_fin', 'motivo', 'tipo',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function scopePorEmpleado($query, int $id) { return $query->where('id_empleado', $id); }
    public function scopePorFecha($query, string $fecha) { return $query->where('fecha', $fecha); }
    public function scopeHorasExtra($query) { return $query->where('tipo', 'hora_extra'); }
    public function scopeEventos($query) { return $query->where('tipo', 'evento'); }

    /**
     * Calcula la duraci+¦n en horas de este registro.
     */
    public function getDuracionHoras(): float
    {
        $inicio = strtotime($this->hora_inicio);
        $fin = strtotime($this->hora_fin);
        if ($fin <= $inicio) $fin += 86400; // cruza medianoche
        return ($fin - $inicio) / 3600;
    }
}
