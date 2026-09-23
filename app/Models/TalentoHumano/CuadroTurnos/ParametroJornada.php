<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ParametroJornada extends Model
{
    protected $table = 'humtal_parametros_jornada';

    protected $fillable = [
        'id_empresa',
        'horas_max_dia',
        'horas_max_semana',
        'horas_max_mes',
        'jornada_diurna_inicio',
        'jornada_diurna_fin',
        'jornada_nocturna_inicio',
        'jornada_nocturna_fin',
        'vigente_desde',
        'vigente_hasta',
        'activo',
        'observacion',
    ];

    protected $casts = [
        'horas_max_dia'    => 'decimal:2',
        'horas_max_semana' => 'decimal:2',
        'horas_max_mes'    => 'decimal:2',
        'vigente_desde'    => 'date',
        'vigente_hasta'    => 'date',
        'activo'           => 'boolean',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivos($query) { return $query->where('activo', true); }

    public function scopePorEmpresa($query, ?int $idEmpresa)
    {
        return $query->where('id_empresa', $idEmpresa);
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'id_empresa');
    }

    // =========================================================================
    // M+�TODOS EST+�TICOS
    // =========================================================================

    /**
     * Obtiene el par+�metro de jornada vigente para una fecha dada,
     * opcionalmente filtrado por empresa.
     * Si no encuentra ninguno, retorna valores por defecto Colombia.
     */
    public static function vigenteEn($fecha = null, ?int $idEmpresa = null): self
    {
        $fecha = $fecha ? Carbon::parse($fecha)->toDateString() : now()->toDateString();

        $parametro = self::where('vigente_desde', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('vigente_hasta')
                  ->orWhere('vigente_hasta', '>=', $fecha);
            })
            ->where('activo', true)
            ->when($idEmpresa !== null, fn($q) => $q->where('id_empresa', $idEmpresa))
            ->orderByDesc('vigente_desde')
            ->first();

        // Si no hay par+�metro en BD, retornar uno con valores por defecto
        if (!$parametro) {
            $parametro = new self([
                'horas_max_dia'          => 8,
                'horas_max_semana'       => 46,
                'horas_max_mes'          => 200,
                'jornada_diurna_inicio'  => '06:00',
                'jornada_diurna_fin'     => '21:00',
                'jornada_nocturna_inicio' => '21:00',
                'jornada_nocturna_fin'   => '06:00',
                'vigente_desde'          => '2020-01-01',
            ]);
        }

        return $parametro;
    }

    /**
     * Obtiene el par+�metro vigente actual.
     */
    public static function vigente(?int $idEmpresa = null): self
    {
        return self::vigenteEn(now(), $idEmpresa);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Retorna el inicio de la jornada nocturna en minutos del d+�a.
     */
    public function getNocturnoInicioMinutos(): int
    {
        return $this->horaAMinutos($this->jornada_nocturna_inicio ?? '21:00');
    }

    /**
     * Retorna el fin de la jornada nocturna en minutos del d+�a.
     */
    public function getNocturnoFinMinutos(): int
    {
        return $this->horaAMinutos($this->jornada_nocturna_fin ?? '06:00');
    }

    /**
     * Convierte HH:MM a minutos del d+�a.
     */
    private function horaAMinutos(string $hora): int
    {
        $parts = explode(':', $hora);
        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }
}
