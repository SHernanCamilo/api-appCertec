<?php

namespace App\Services\Turnos;

use App\Models\Turnos\CtAsignacion;
use App\Models\Turnos\CtFestivo;
use Carbon\Carbon;

/**
 * Servicio para calcular las horas trabajadas por categoría.
 *
 * 4 categorías de horas:
 *   - normales:           diurnas en día laboral (lunes-sábado no festivo)
 *   - nocturnas:          nocturnas en día laboral
 *   - festivas:           diurnas en domingo o festivo
 *   - festivas_nocturnas: nocturnas en domingo o festivo
 *
 * Reglas:
 *   - Horario nocturno (Colombia): 21:00 - 06:00
 *   - Festivo: domingo OR fecha registrada en humtal_ct_festivos
 *   - Si un rango cruza el límite (ej. 20:00-22:00), se divide por minuto
 *   - Si un turno cruza la medianoche, se divide por día (parte antes 24:00 / parte después)
 *
 * NO calcula porcentajes de recargo. Solo cantidad de horas por categoría.
 */
class CalculoHorasService
{
    /** Hora desde la que comienza el horario nocturno (21:00). */
    private const NOCTURNO_INICIO = 21 * 60; // 21:00 en minutos

    /** Hora hasta la que dura el horario nocturno (06:00 del día siguiente). */
    private const NOCTURNO_FIN = 6 * 60;     // 06:00 en minutos

    /**
     * Calcula totales de un mes para un empleado.
     *
     * @return array{
     *   anio:int,mes:int,
     *   total:float,normales:float,nocturnas:float,festivas:float,festivas_nocturnas:float,
     *   por_dia:array<string,array>
     * }
     */
    public function calcularMesEmpleado(int $idEmpleado, int $anio, int $mes): array
    {
        $inicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
        $fin    = Carbon::createFromDate($anio, $mes, 1)->endOfMonth();

        return $this->calcularRango($idEmpleado, $inicio->toDateString(), $fin->toDateString())
            + ['anio' => $anio, 'mes' => $mes];
    }

    /**
     * Calcula totales de un rango de fechas para un empleado.
     */
    public function calcularRango(int $idEmpleado, string $desde, string $hasta): array
    {
        $asignaciones = CtAsignacion::with('plantilla')
            ->where('id_empleado', $idEmpleado)
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('es_descanso', false)
            ->orderBy('fecha')
            ->get();

        // Precarga festivos del rango para evitar N+1
        $festivos = array_flip(CtFestivo::fechasEnRango($desde, $hasta));

        $totales = [
            'normales'           => 0.0,
            'nocturnas'          => 0.0,
            'festivas'           => 0.0,
            'festivas_nocturnas' => 0.0,
        ];
        $porDia = [];

        foreach ($asignaciones as $asig) {
            $fecha = Carbon::parse($asig->fecha)->format('Y-m-d');
            $diaTotales = $this->calcularDia($asig, $fecha, $festivos);

            $porDia[$fecha] = $diaTotales;
            foreach ($diaTotales as $k => $v) {
                if (isset($totales[$k])) {
                    $totales[$k] += $v;
                }
            }
        }

        $total = array_sum($totales);

        return [
            'desde'              => $desde,
            'hasta'              => $hasta,
            'total'              => round($total, 2),
            'normales'           => round($totales['normales'], 2),
            'nocturnas'          => round($totales['nocturnas'], 2),
            'festivas'           => round($totales['festivas'], 2),
            'festivas_nocturnas' => round($totales['festivas_nocturnas'], 2),
            'por_dia'            => $porDia,
        ];
    }

    /**
     * Calcula el total por día, considerando que un turno puede cruzar la medianoche.
     * Si cruza, distribuye la parte antes de 24:00 al día actual y la parte después al día siguiente.
     */
    private function calcularDia(CtAsignacion $asig, string $fecha, array $festivosFlip): array
    {
        $totales = [
            'normales'           => 0.0,
            'nocturnas'          => 0.0,
            'festivas'           => 0.0,
            'festivas_nocturnas' => 0.0,
            'rangos'             => [],
        ];

        foreach ($asig->getRangos() as $rango) {
            $inicioMin = $this->aMinutos($rango['inicio']);
            $finMin    = $this->aMinutos($rango['fin']);

            // Cruce de medianoche: el rango termina al día siguiente
            if ($finMin <= $inicioMin) {
                // Parte 1: del inicio hasta 24:00 en el día actual
                $this->acumular($totales, $fecha, $inicioMin, 24 * 60, $festivosFlip);

                // Parte 2: de 00:00 hasta finMin en el día siguiente
                $diaSig = Carbon::parse($fecha)->addDay()->format('Y-m-d');
                $this->acumular($totales, $diaSig, 0, $finMin, $festivosFlip);
            } else {
                $this->acumular($totales, $fecha, $inicioMin, $finMin, $festivosFlip);
            }

            $totales['rangos'][] = $rango;
        }

        return $totales;
    }

    /**
     * Acumula minutos en cada categoría para un sub-rango dentro de un mismo día.
     */
    private function acumular(array &$totales, string $fecha, int $desdeMin, int $hastaMin, array $festivosFlip): void
    {
        if ($hastaMin <= $desdeMin) {
            return;
        }

        $esFestivoDia = $this->esFestivo($fecha, $festivosFlip);

        // Recorre cada minuto y clasifica. Es seguro y simple: máximo 1440 iteraciones por día.
        // Para mayor rendimiento se puede optimizar pero esto es claro y correcto.
        $minutosTotales = $hastaMin - $desdeMin;
        $minutosNocturnos = 0;

        for ($m = $desdeMin; $m < $hastaMin; $m++) {
            if ($this->esMinutoNocturno($m)) {
                $minutosNocturnos++;
            }
        }

        $minutosDiurnos = $minutosTotales - $minutosNocturnos;

        $horasDiurnas   = $minutosDiurnos / 60;
        $horasNocturnas = $minutosNocturnos / 60;

        if ($esFestivoDia) {
            $totales['festivas']           += $horasDiurnas;
            $totales['festivas_nocturnas'] += $horasNocturnas;
        } else {
            $totales['normales']  += $horasDiurnas;
            $totales['nocturnas'] += $horasNocturnas;
        }
    }

    /**
     * Determina si un minuto del día (0..1440) está en horario nocturno.
     * Nocturno: 21:00 - 24:00 OR 00:00 - 06:00
     */
    private function esMinutoNocturno(int $minutoDelDia): bool
    {
        return $minutoDelDia >= self::NOCTURNO_INICIO || $minutoDelDia < self::NOCTURNO_FIN;
    }

    /**
     * Determina si una fecha es festivo (domingo o festivo registrado).
     */
    private function esFestivo(string $fecha, array $festivosFlip): bool
    {
        if (isset($festivosFlip[$fecha])) {
            return true;
        }
        // Domingo = 0
        return Carbon::parse($fecha)->dayOfWeek === Carbon::SUNDAY;
    }

    /**
     * Convierte 'HH:MM' o 'HH:MM:SS' a minutos del día.
     */
    private function aMinutos(string $hora): int
    {
        $parts = explode(':', $hora);
        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }
}
