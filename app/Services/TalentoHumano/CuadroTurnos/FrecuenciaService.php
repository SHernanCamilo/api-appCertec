<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

use App\Models\TalentoHumano\CuadroTurnos\CtFrecuencia;
use App\Models\TalentoHumano\CuadroTurnos\CtFestivo;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use App\Models\TalentoHumano\CuadroTurnos\CtAsignacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FrecuenciaService
{
    private CuadroTurnoService $cuadroService;

    public function __construct(CuadroTurnoService $cuadroService)
    {
        $this->cuadroService = $cuadroService;
    }

    public function crear(array $data): CtFrecuencia
    {
        return CtFrecuencia::create($data);
    }

    public function actualizar(int $id, array $data): CtFrecuencia
    {
        $frecuencia = CtFrecuencia::findOrFail($id);
        $frecuencia->update($data);
        return $frecuencia->fresh();
    }

    public function eliminar(int $id): bool
    {
        $frecuencia = CtFrecuencia::findOrFail($id);
        $frecuencia->update(['estado' => false]);
        return true;
    }

    public function generarAsignaciones(CtFrecuencia $frecuencia): array
    {
        $fechas = $this->calcularFechas($frecuencia);

        if (empty($fechas)) {
            return ['exitosas' => [], 'errores' => [], 'total' => 0, 'total_ok' => 0, 'total_err' => 0];
        }

        $fechas = $this->filtrarFechas($fechas, $frecuencia);

        if (empty($fechas)) {
            return ['exitosas' => [], 'errores' => [], 'total' => 0, 'total_ok' => 0, 'total_err' => 0];
        }

        return $this->crearAsignacionesMasivas($frecuencia, $fechas);
    }

    public function generarDesdeConfiguracion(array $config): array
    {
        $frecuencia = new CtFrecuencia($config);
        $frecuencia->fecha_inicio = Carbon::parse($config['fecha_inicio']);
        $frecuencia->fecha_fin = Carbon::parse($config['fecha_fin']);

        $fechas = $this->calcularFechas($frecuencia);
        $fechas = $this->filtrarFechas($fechas, $frecuencia);

        return [
            'fechas'          => array_map(fn(Carbon $f) => $f->toDateString(), $fechas),
            'total_fechas'    => count($fechas),
            'tipo_frecuencia' => $config['tipo_frecuencia'] ?? 'sin_programacion',
        ];
    }

    private function calcularFechas(CtFrecuencia $frecuencia): array
    {
        $fechaInicio = Carbon::parse($frecuencia->fecha_inicio);
        $fechaFin = Carbon::parse($frecuencia->fecha_fin);

        if ($fechaFin->lt($fechaInicio)) return [];

        return match ($frecuencia->tipo_frecuencia) {
            CtFrecuencia::TIPO_SIN_PROGRAMACION => [],
            CtFrecuencia::TIPO_POR_NUMERO_DIAS  => $this->calcularPorNumeroDias($fechaInicio, $fechaFin, $frecuencia->cada_n_dias),
            CtFrecuencia::TIPO_POR_DIAS_SEMANA  => $this->calcularPorDiasSemana($fechaInicio, $fechaFin, $frecuencia->dias_semana ?? []),
            CtFrecuencia::TIPO_DIAS_DEL_MES     => $this->calcularPorDiasMes($fechaInicio, $fechaFin, $frecuencia->dias_mes ?? []),
            default => [],
        };
    }

    private function calcularPorNumeroDias(Carbon $inicio, Carbon $fin, ?int $cadaNDias): array
    {
        if (!$cadaNDias || $cadaNDias < 1) return [];
        $fechas = [];
        $actual = $inicio->copy();
        while ($actual->lte($fin)) {
            $fechas[] = $actual->copy();
            $actual->addDays($cadaNDias);
        }
        return $fechas;
    }

    private function calcularPorDiasSemana(Carbon $inicio, Carbon $fin, array $diasSemana): array
    {
        if (empty($diasSemana)) return [];
        $fechas = [];
        $actual = $inicio->copy();
        while ($actual->lte($fin)) {
            if (in_array($actual->dayOfWeek, $diasSemana)) {
                $fechas[] = $actual->copy();
            }
            $actual->addDay();
        }
        return $fechas;
    }

    private function calcularPorDiasMes(Carbon $inicio, Carbon $fin, array $diasMes): array
    {
        if (empty($diasMes)) return [];
        sort($diasMes);
        $fechas = [];
        $mesActual = $inicio->copy()->startOfMonth();
        $mesFin = $fin->copy()->endOfMonth();

        while ($mesActual->lte($mesFin)) {
            $diasEnMes = $mesActual->daysInMonth;
            foreach ($diasMes as $dia) {
                if ($dia < 1 || $dia > $diasEnMes) continue;
                $fecha = $mesActual->copy()->day($dia);
                if ($fecha->gte($inicio) && $fecha->lte($fin)) {
                    $fechas[] = $fecha;
                }
            }
            $mesActual->addMonth();
        }
        return $fechas;
    }

    private function filtrarFechas(array $fechas, CtFrecuencia $frecuencia): array
    {
        if (empty($fechas)) return [];

        $festivosDelRango = [];
        if (!$frecuencia->incluir_festivos) {
            $fechaInicio = Carbon::parse($frecuencia->fecha_inicio);
            $fechaFin = Carbon::parse($frecuencia->fecha_fin);
            $festivosDelRango = CtFestivo::where('estado', true)
                ->whereBetween('fecha', [$fechaInicio->toDateString(), $fechaFin->toDateString()])
                ->pluck('fecha')
                ->map(fn($f) => Carbon::parse($f)->toDateString())
                ->toArray();
        }

        return array_values(array_filter($fechas, function (Carbon $fecha) use ($frecuencia, $festivosDelRango) {
            if (!$frecuencia->incluir_dominicales && $fecha->isSunday()) return false;
            if (!$frecuencia->incluir_festivos && in_array($fecha->toDateString(), $festivosDelRango)) return false;
            return true;
        }));
    }

    private function crearAsignacionesMasivas(CtFrecuencia $frecuencia, array $fechas): array
    {
        $exitosas = [];
        $errores = [];

        $fechasPorMes = [];
        foreach ($fechas as $fecha) {
            $clave = $fecha->format('Y-m');
            $fechasPorMes[$clave][] = $fecha;
        }

        DB::beginTransaction();
        try {
            foreach ($fechasPorMes as $mesKey => $fechasDelMes) {
                [$anio, $mes] = explode('-', $mesKey);
                $anio = (int) $anio;
                $mes = (int) $mes;

                $idCuadro = $this->obtenerOCrearCuadro($frecuencia->id_empleado, $anio, $mes);

                if (!$idCuadro) {
                    foreach ($fechasDelMes as $fecha) {
                        $errores[] = ['fecha' => $fecha->toDateString(), 'error' => "No se pudo obtener cuadro para {$mes}/{$anio}"];
                    }
                    continue;
                }

                foreach ($fechasDelMes as $fecha) {
                    try {
                        $asignacion = CtAsignacion::updateOrCreate(
                            [
                                'id_cuadro'   => $idCuadro,
                                'id_empleado' => $frecuencia->id_empleado,
                                'fecha'       => $fecha->toDateString(),
                            ],
                            [
                                'id_plantilla'         => $frecuencia->es_descanso ? null : $frecuencia->id_plantilla,
                                'es_descanso'          => $frecuencia->es_descanso ?? false,
                                'es_festivo'           => $this->esFestivo($fecha),
                                'hora_inicio_override' => $frecuencia->hora_inicio_override ?: null,
                                'hora_fin_override'    => $frecuencia->hora_fin_override ?: null,
                                'observacion'          => $frecuencia->observacion ?: null,
                            ]
                        );
                        $exitosas[] = $asignacion;
                    } catch (\Exception $e) {
                        $errores[] = ['fecha' => $fecha->toDateString(), 'error' => $e->getMessage()];
                    }
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return ['exitosas' => [], 'errores' => [['fecha' => null, 'error' => $e->getMessage()]], 'total' => count($fechas), 'total_ok' => 0, 'total_err' => count($fechas)];
        }

        return ['exitosas' => $exitosas, 'errores' => $errores, 'total' => count($fechas), 'total_ok' => count($exitosas), 'total_err' => count($errores)];
    }

    private function obtenerOCrearCuadro(int $idEmpleado, int $anio, int $mes): ?int
    {
        // Buscar cuadro existente por empleado
        $cuadroExistente = CtCuadro::where('id_empleado', $idEmpleado)->where('anio', $anio)->where('mes', $mes)->first();
        if ($cuadroExistente) return $cuadroExistente->id;

        // Buscar cuadro por unidad funcional (si la tabla existe)
        try {
            $unidadId = DB::table('config_unidades_fun_tercero')
                ->where('id_tercero', $idEmpleado)
                ->where('estado', true)
                ->value('id_unidad_funcional');
        } catch (\Exception $e) {
            $unidadId = null;
        }

        // Si no encontramos por la tabla de terceros, buscar si hay alg+¦n cuadro
        // de cualquier unidad funcional para este mes que tenga asignaciones de este empleado
        if (!$unidadId) {
            $cuadroConEmpleado = CtCuadro::where('anio', $anio)
                ->where('mes', $mes)
                ->whereHas('asignaciones', function ($q) use ($idEmpleado) {
                    $q->where('id_empleado', $idEmpleado);
                })
                ->first();

            if ($cuadroConEmpleado) return $cuadroConEmpleado->id;

            // Buscar cualquier cuadro activo para este mes (el de la unidad del usuario)
            $cuadroCualquiera = CtCuadro::where('anio', $anio)
                ->where('mes', $mes)
                ->whereNotNull('id_unidad_funcional')
                ->first();

            if ($cuadroCualquiera) return $cuadroCualquiera->id;
        }

        if ($unidadId) {
            $cuadro = CtCuadro::where('id_unidad_funcional', $unidadId)->where('anio', $anio)->where('mes', $mes)->first();
            if ($cuadro) return $cuadro->id;

            $cuadro = CtCuadro::create([
                'id_unidad_funcional' => $unidadId,
                'anio' => $anio, 'mes' => $mes,
                'estado' => 'creado',
                'creado_por' => auth()->id(),
            ]);
            return $cuadro->id;
        }

        // +Ültimo recurso: crear cuadro por empleado
        $cuadro = CtCuadro::create([
            'id_empleado' => $idEmpleado,
            'anio' => $anio, 'mes' => $mes,
            'estado' => 'creado',
            'creado_por' => auth()->id(),
        ]);
        return $cuadro->id;
    }

    private function esFestivo(Carbon $fecha): bool
    {
        if ($fecha->isSunday()) return true;
        return CtFestivo::where('fecha', $fecha->toDateString())->where('estado', true)->exists();
    }
}
