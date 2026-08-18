<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

use App\Models\TalentoHumano\CuadroTurnos\CtGrupo;
use App\Models\TalentoHumano\CuadroTurnos\CtGrupoEncargado;
use App\Models\TalentoHumano\CuadroTurnos\CtGrupoEmpleado;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use App\Models\TalentoHumano\CuadroTurnos\CtAsignacion;
use App\Models\TalentoHumano\CuadroTurnos\CtPlantilla;
use App\Models\TalentoHumano\CuadroTurnos\CtNovedad;
use App\Models\TalentoHumano\CuadroTurnos\CtNovedadTipo;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CuadroTurnoService
{
    // =========================================================================
    // GRUPOS
    // =========================================================================

    /**
     * Crear un nuevo grupo de turnos.
     */
    public function crearGrupo(array $data): CtGrupo
    {
        return CtGrupo::create($data);
    }

    /**
     * Actualizar datos de un grupo.
     */
    public function actualizarGrupo(int $id, array $data): CtGrupo
    {
        $grupo = CtGrupo::findOrFail($id);
        $grupo->update($data);
        return $grupo->fresh();
    }

    /**
     * Asignar un nuevo encargado al grupo.
     * Cierra el encargado actual (fecha_fin = hoy) y crea el nuevo registro.
     */
    public function asignarEncargado(
        int $idGrupo,
        int $idUser,
        string $fechaInicio,
        ?string $motivo,
        int $registradoPor
    ): CtGrupoEncargado {
        return DB::transaction(function () use ($idGrupo, $idUser, $fechaInicio, $motivo, $registradoPor) {
            // Cerrar encargado actual si existe
            CtGrupoEncargado::where('id_grupo', $idGrupo)
                ->whereNull('fecha_fin')
                ->update(['fecha_fin' => Carbon::today()->toDateString()]);

            // Crear nuevo encargado
            return CtGrupoEncargado::create([
                'id_grupo'      => $idGrupo,
                'id_user'       => $idUser,
                'fecha_inicio'  => $fechaInicio,
                'fecha_fin'     => null,
                'motivo_cambio' => $motivo,
                'registrado_por' => $registradoPor,
            ]);
        });
    }

    /**
     * Agregar un empleado a un grupo.
     */
    public function agregarEmpleado(int $idGrupo, int $idEmpleado, string $fechaIngreso): CtGrupoEmpleado
    {
        // Verificar que no esté ya activo en el grupo
        $existeActivo = CtGrupoEmpleado::where('id_grupo', $idGrupo)
            ->where('id_empleado', $idEmpleado)
            ->whereNull('fecha_salida')
            ->where('estado', true)
            ->exists();

        if ($existeActivo) {
            throw new \Exception('El empleado ya está activo en este grupo.');
        }

        return CtGrupoEmpleado::create([
            'id_grupo'      => $idGrupo,
            'id_empleado'   => $idEmpleado,
            'fecha_ingreso' => $fechaIngreso,
            'fecha_salida'  => null,
            'estado'        => true,
        ]);
    }

    /**
     * Retirar un empleado de un grupo (registra fecha de salida).
     */
    public function retirarEmpleado(int $idGrupo, int $idEmpleado, string $fechaSalida): CtGrupoEmpleado
    {
        $registro = CtGrupoEmpleado::where('id_grupo', $idGrupo)
            ->where('id_empleado', $idEmpleado)
            ->whereNull('fecha_salida')
            ->where('estado', true)
            ->firstOrFail();

        $registro->update([
            'fecha_salida' => $fechaSalida,
            'estado'       => false,
        ]);

        return $registro->fresh();
    }

    // =========================================================================
    // CUADRO
    // =========================================================================

    /**
     * Crear un nuevo cuadro de turnos para un grupo/mes/año.
     * Valida que no exista ya ese grupo/anio/mes.
     */
    public function crearCuadro(int $idGrupo, int $anio, int $mes, int $creadoPor): CtCuadro
    {
        // Validar que el grupo exista y esté activo
        $grupo = CtGrupo::where('id', $idGrupo)->where('estado', true)->firstOrFail();

        // Validar que no exista ya un cuadro para ese período
        $existe = CtCuadro::where('id_grupo', $idGrupo)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->exists();

        if ($existe) {
            throw new \Exception("Ya existe un cuadro para el grupo '{$grupo->nombre}' en el período {$mes}/{$anio}.");
        }

        return CtCuadro::create([
            'id_grupo'   => $idGrupo,
            'anio'       => $anio,
            'mes'        => $mes,
            'estado'     => CtCuadro::ESTADO_BORRADOR,
            'creado_por' => $creadoPor,
        ]);
    }

    /**
     * Publicar un cuadro (solo si está en borrador).
     */
    public function publicarCuadro(int $id, int $userId): CtCuadro
    {
        $cuadro = CtCuadro::findOrFail($id);

        if (!$cuadro->esBorrador()) {
            throw new \Exception('Solo se puede publicar un cuadro en estado borrador.');
        }

        $cuadro->update([
            'estado'            => CtCuadro::ESTADO_PUBLICADO,
            'publicado_por'     => $userId,
            'fecha_publicacion' => now(),
        ]);

        return $cuadro->fresh();
    }

    /**
     * Cerrar un cuadro (solo si está publicado).
     */
    public function cerrarCuadro(int $id, int $userId): CtCuadro
    {
        $cuadro = CtCuadro::findOrFail($id);

        if (!$cuadro->esPublicado()) {
            throw new \Exception('Solo se puede cerrar un cuadro en estado publicado.');
        }

        $cuadro->update([
            'estado'      => CtCuadro::ESTADO_CERRADO,
            'cerrado_por' => $userId,
            'fecha_cierre' => now(),
        ]);

        return $cuadro->fresh();
    }

    // =========================================================================
    // ASIGNACIONES
    // =========================================================================

    /**
     * Asignar un turno a un empleado en una fecha.
     * Soporta jornada partida (segundo rango opcional).
     * Valida solapamiento de horario antes de guardar.
     */
    public function asignarTurno(array $data): CtAsignacion
    {
        // Verificar que el cuadro esté en borrador
        $cuadro = CtCuadro::findOrFail($data['id_cuadro']);
        if (!$cuadro->esBorrador()) {
            throw new \Exception('Solo se pueden modificar asignaciones en cuadros en estado borrador.');
        }

        // Validar solapamiento si se asigna una plantilla (no descanso)
        $idPlantilla = $data['id_plantilla'] ?? null;
        $esDescanso  = $data['es_descanso'] ?? false;

        if (!$esDescanso && $idPlantilla) {
            $haySolapamiento = $this->validarSolapamiento(
                $data['id_empleado'],
                $data['fecha'],
                $idPlantilla,
                $data['hora_inicio_override'] ?? null,
                $data['hora_fin_override'] ?? null,
                $data['id'] ?? null,
                $data['hora_inicio_override_2'] ?? null,
                $data['hora_fin_override_2'] ?? null
            );

            if ($haySolapamiento) {
                throw new \Exception('El empleado ya tiene un turno asignado que se solapa con el horario indicado en esa fecha.');
            }
        }

        return CtAsignacion::updateOrCreate(
            [
                'id_cuadro'   => $data['id_cuadro'],
                'id_empleado' => $data['id_empleado'],
                'fecha'       => $data['fecha'],
            ],
            [
                'id_plantilla'           => $idPlantilla,
                'es_descanso'            => $esDescanso,
                'es_festivo'             => $data['es_festivo'] ?? false,
                'hora_inicio_override'   => $data['hora_inicio_override'] ?? null,
                'hora_fin_override'      => $data['hora_fin_override'] ?? null,
                'hora_inicio_override_2' => $data['hora_inicio_override_2'] ?? null,
                'hora_fin_override_2'    => $data['hora_fin_override_2'] ?? null,
                'observacion'            => $data['observacion'] ?? null,
            ]
        );
    }

    /**
     * Asignar múltiples turnos en lote.
     * Retorna array con resultados: ['exitosas' => [...], 'errores' => [...]]
     */
    public function asignarTurnoMasivo(int $idCuadro, array $asignaciones): array
    {
        $exitosas = [];
        $errores  = [];

        foreach ($asignaciones as $index => $asignacion) {
            try {
                $asignacion['id_cuadro'] = $idCuadro;
                $resultado = $this->asignarTurno($asignacion);
                $exitosas[] = $resultado;
            } catch (\Exception $e) {
                $errores[] = [
                    'index'      => $index,
                    'asignacion' => $asignacion,
                    'error'      => $e->getMessage(),
                ];
            }
        }

        return [
            'exitosas' => $exitosas,
            'errores'  => $errores,
            'total'    => count($asignaciones),
            'total_ok' => count($exitosas),
            'total_err' => count($errores),
        ];
    }

    /**
     * Validar si hay solapamiento de horario para un empleado en una fecha.
     *
     * Retorna TRUE si hay solapamiento (conflicto), FALSE si no hay.
     *
     * Soporta jornada partida: valida ambos rangos (Turno 1 y Turno 2 opcional).
     * Lógica: dos rangos se solapan si inicio1 < fin2 AND fin1 > inicio2
     */
    public function validarSolapamiento(
        int $idEmpleado,
        string $fecha,
        ?int $idPlantilla,
        ?string $horaInicioOverride = null,
        ?string $horaFinOverride = null,
        ?int $excluirAsignacionId = null,
        ?string $horaInicioOverride2 = null,
        ?string $horaFinOverride2 = null
    ): bool {
        if (!$idPlantilla) {
            return false; // Descanso, no hay solapamiento
        }

        $plantillaNueva = CtPlantilla::find($idPlantilla);
        if (!$plantillaNueva) {
            return false;
        }

        // Construir lista de rangos de la nueva asignación (1 o 2 rangos)
        $rangosNuevos = [];
        $rangosNuevos[] = [
            'inicio' => $horaInicioOverride ?? $plantillaNueva->hora_inicio,
            'fin'    => $horaFinOverride    ?? $plantillaNueva->hora_fin,
        ];

        $inicio2 = $horaInicioOverride2 ?? $plantillaNueva->hora_inicio_2;
        $fin2    = $horaFinOverride2    ?? $plantillaNueva->hora_fin_2;
        if ($inicio2 && $fin2) {
            $rangosNuevos[] = ['inicio' => $inicio2, 'fin' => $fin2];
        }

        // Buscar todas las asignaciones del empleado en esa fecha
        $query = CtAsignacion::where('id_empleado', $idEmpleado)
            ->where('fecha', $fecha)
            ->where('es_descanso', false)
            ->whereNotNull('id_plantilla')
            ->with('plantilla');

        if ($excluirAsignacionId) {
            $query->where('id', '!=', $excluirAsignacionId);
        }

        $asignacionesExistentes = $query->get();

        foreach ($asignacionesExistentes as $asignacion) {
            $rangosExistentes = $asignacion->getRangos();

            foreach ($rangosNuevos as $rangoNuevo) {
                foreach ($rangosExistentes as $rangoExistente) {
                    // Dos rangos se solapan si: inicio1 < fin2 AND fin1 > inicio2
                    if ($rangoNuevo['inicio'] < $rangoExistente['fin']
                        && $rangoNuevo['fin'] > $rangoExistente['inicio']) {
                        return true;
                    }
                }
            }

            // Validar también solapamiento entre los dos rangos de la nueva asignación
            if (count($rangosNuevos) === 2) {
                if ($rangosNuevos[0]['inicio'] < $rangosNuevos[1]['fin']
                    && $rangosNuevos[0]['fin'] > $rangosNuevos[1]['inicio']) {
                    return true;
                }
            }
        }

        // Validación adicional: aunque no haya asignaciones existentes,
        // verificar coherencia interna de la nueva (rango 1 vs rango 2)
        if (count($rangosNuevos) === 2) {
            if ($rangosNuevos[0]['inicio'] < $rangosNuevos[1]['fin']
                && $rangosNuevos[0]['fin'] > $rangosNuevos[1]['inicio']) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // NOVEDADES
    // =========================================================================

    /**
     * Crear una novedad para un empleado.
     */
    public function crearNovedad(array $data): CtNovedad
    {
        return DB::transaction(function () use ($data) {
            $tipo = CtNovedadTipo::findOrFail($data['id_novedad_tipo']);

            // Si requiere reemplazo, validar que se haya enviado
            if ($tipo->requiere_reemplazo && empty($data['id_empleado_reemplaza'])) {
                throw new \Exception("El tipo de novedad '{$tipo->nombre}' requiere especificar un empleado de reemplazo.");
            }

            return CtNovedad::create($data);
        });
    }

    /**
     * Aprobar una novedad pendiente.
     */
    public function aprobarNovedad(int $id, int $userId, ?string $comentario): CtNovedad
    {
        $novedad = CtNovedad::findOrFail($id);

        if ($novedad->estado !== CtNovedad::ESTADO_PENDIENTE) {
            throw new \Exception('Solo se pueden aprobar novedades en estado pendiente.');
        }

        $novedad->update([
            'estado'               => CtNovedad::ESTADO_APROBADO,
            'aprobado_por'         => $userId,
            'fecha_aprobacion'     => now(),
            'comentario_aprobacion' => $comentario,
        ]);

        return $novedad->fresh();
    }

    /**
     * Rechazar una novedad pendiente.
     */
    public function rechazarNovedad(int $id, int $userId, string $comentario): CtNovedad
    {
        $novedad = CtNovedad::findOrFail($id);

        if ($novedad->estado !== CtNovedad::ESTADO_PENDIENTE) {
            throw new \Exception('Solo se pueden rechazar novedades en estado pendiente.');
        }

        $novedad->update([
            'estado'               => CtNovedad::ESTADO_RECHAZADO,
            'aprobado_por'         => $userId,
            'fecha_aprobacion'     => now(),
            'comentario_aprobacion' => $comentario,
        ]);

        return $novedad->fresh();
    }

    // =========================================================================
    // CONSULTAS
    // =========================================================================

    /**
     * Obtener la grilla completa del cuadro: empleados × días del mes.
     *
     * Retorna:
     * [
     *   'cuadro'     => CtCuadro,
     *   'empleados'  => [{ id, nombre, cargo }],
     *   'dias'       => [1, 2, ..., 31],
     *   'grilla'     => [ id_empleado => [ 'YYYY-MM-DD' => asignacion ] ]
     * ]
     */
    public function obtenerCuadroGrilla(int $idCuadro): array
    {
        $cuadro = CtCuadro::with([
            'grupo.empleados' => function ($q) {
                $q->activos()->with(['empleado.cargoRelacion']);
            },
            'asignaciones.plantilla',
            'asignaciones.empleado',
        ])->findOrFail($idCuadro);

        // Días del mes
        $diasEnMes = Carbon::createFromDate($cuadro->anio, $cuadro->mes, 1)->daysInMonth;
        $dias = range(1, $diasEnMes);

        // Empleados activos del grupo
        $empleados = $cuadro->grupo->empleados
            ->map(function ($ge) {
                return [
                    'id'     => $ge->empleado->id,
                    'nombre' => $ge->empleado->nombre ?? 'Sin nombre',
                    'cargo'  => $ge->empleado->cargoRelacion->nombre_cargo ?? null,
                ];
            })
            ->values()
            ->toArray();

        // Indexar asignaciones por empleado y fecha
        $grilla = [];
        foreach ($cuadro->asignaciones as $asignacion) {
            $fechaKey = Carbon::parse($asignacion->fecha)->format('Y-m-d');
            $grilla[$asignacion->id_empleado][$fechaKey] = [
                'id'           => $asignacion->id,
                'es_descanso'  => $asignacion->es_descanso,
                'es_festivo'   => $asignacion->es_festivo,
                'id_plantilla' => $asignacion->id_plantilla,
                'plantilla'    => $asignacion->plantilla ? [
                    'id'             => $asignacion->plantilla->id,
                    'codigo'         => $asignacion->plantilla->codigo,
                    'nombre'         => $asignacion->plantilla->nombre,
                    'hora_inicio'    => $asignacion->getHoraInicio(),
                    'hora_fin'       => $asignacion->getHoraFin(),
                    'hora_inicio_2'  => $asignacion->getHoraInicio2(),
                    'hora_fin_2'     => $asignacion->getHoraFin2(),
                    'es_jornada_partida' => $asignacion->esJornadaPartida(),
                    'color_hex'      => $asignacion->plantilla->color_hex,
                ] : null,
                'rangos'       => $asignacion->getRangos(),
                'observacion'  => $asignacion->observacion,
            ];
        }

        return [
            'cuadro'    => $cuadro,
            'empleados' => $empleados,
            'dias'      => $dias,
            'grilla'    => $grilla,
        ];
    }

    /**
     * Obtener todos los turnos de un empleado en un mes/año (puede ser de varios cuadros/grupos).
     */
    public function obtenerTurnosEmpleado(int $idEmpleado, int $anio, int $mes): array
    {
        $fechaInicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth()->toDateString();
        $fechaFin    = Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();

        // Get the assignment records
        $asignaciones = \DB::table('humtal_ct_asignacion as ha')
            ->where('ha.id_empleado', $idEmpleado)
            ->whereBetween('ha.fecha', [$fechaInicio, $fechaFin])
            ->select(
                'ha.id',
                'ha.fecha',
                'ha.es_descanso',
                'ha.es_festivo',
                'ha.hora_inicio_override',
                'ha.hora_fin_override',
                'ha.hora_inicio_override_2',
                'ha.hora_fin_override_2',
                'ha.id_plantilla',
                'ha.id_empleado',
                'ha.observacion'
            )
            ->orderBy('ha.fecha')
            ->get();

        // Get empleado name - try to find in users first, if not found check config_person_tercero
        $empleadoData = \DB::table('users')->find($idEmpleado);
        
        if (!$empleadoData) {
            $empleadoData = \DB::table('config_person_tercero')->find($idEmpleado);
        }

        $empleadoNombre = $empleadoData ? ($empleadoData->name ?? $empleadoData->nombre ?? 'Desconocido') : 'Desconocido';

        return $asignaciones->map(function ($a) use ($empleadoNombre) {
            $plantilla = \DB::table('humtal_ct_plantillas')->find($a->id_plantilla);
            
            // Usar override si existe, sino usar plantilla
            $horaInicio = $a->hora_inicio_override ?? ($plantilla->hora_inicio ?? '00:00:00');
            $horaFin = $a->hora_fin_override ?? ($plantilla->hora_fin ?? '00:00:00');
            $horaInicio2 = $a->hora_inicio_override_2;
            $horaFin2 = $a->hora_fin_override_2;
            
            return [
                'id'             => $a->id,
                'fecha'          => Carbon::parse($a->fecha)->format('Y-m-d'),
                'es_descanso'    => $a->es_descanso,
                'es_festivo'     => $a->es_festivo,
                'hora_inicio'    => $horaInicio,
                'hora_fin'       => $horaFin,
                'hora_inicio_2'  => $horaInicio2,
                'hora_fin_2'     => $horaFin2,
                'es_jornada_partida' => false,
                'rangos'         => $plantilla ? [['inicio' => $plantilla->hora_inicio, 'fin' => $plantilla->hora_fin]] : [],
                'plantilla'      => $plantilla ? [
                    'id'        => $plantilla->id,
                    'codigo'    => $plantilla->codigo,
                    'nombre'    => $plantilla->nombre,
                    'color_hex' => $plantilla->color_hex ?? '#000000',
                ] : null,
                'grupo'          => [
                    'id'     => $a->id_empleado,
                    'nombre' => 'Individual: ' . $empleadoNombre,
                ],
                'observacion'    => $a->observacion,
            ];
        })->toArray();
    }

    /**
     * Obtener cuadro completo del empleado con turnos, totales y festivos
     */
    public function obtenerCuadroEmpleado(int $idEmpleado, int $anio, int $mes): array
    {
        try {
            $fechaInicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth()->toDateString();
            $fechaFin    = Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();

            // Obtener datos del empleado desde config_person_tercero
            $empleado = \DB::table('config_person_tercero')
                ->where('id', $idEmpleado)
                ->select('id', 'nombre', 'email')
                ->first();

            $turnos = $this->obtenerTurnosEmpleado($idEmpleado, $anio, $mes);
            $festivos = $this->obtenerFestivosDelAnio($anio);
            $totales = $this->calcularTotalesMes($turnos, $festivos, $fechaInicio, $fechaFin);
            $porDia = $this->desglosarPorDia($turnos, $festivos, $fechaInicio, $fechaFin);

            return [
                'empleado'  => $empleado ? (array) $empleado : ['id' => $idEmpleado, 'nombre' => 'Desconocido'],
                'anio'      => $anio,
                'mes'       => $mes,
                'turnos'    => $turnos,
                'totales'   => $totales,
                'por_dia'   => $porDia,
                'festivos'  => $festivos,
            ];
        } catch (\Exception $e) {
            throw new \Exception('Error al obtener cuadro del empleado: ' . $e->getMessage());
        }
    }
/**
 * Eliminar todos los turnos de un empleado en un mes/año
 */
public function eliminarCuadroEmpleado(int $idEmpleado, int $anio, int $mes): array
{
    try {
        $fechaInicio = Carbon::createFromDate($anio, $mes, 1)->startOfMonth()->toDateString();
        $fechaFin    = Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();

        $asignacionesEliminadas = CtAsignacion::where('id_empleado', $idEmpleado)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->delete();

        return [
            'id_empleado'             => $idEmpleado,
            'anio'                    => $anio,
            'mes'                     => $mes,
            'asignaciones_eliminadas' => $asignacionesEliminadas,
            'mensaje'                 => "Se eliminaron {$asignacionesEliminadas} asignaciones del empleado en {$mes}/{$anio}",
        ];
    } catch (\Exception $e) {
        throw new \Exception('Error al eliminar cuadro del empleado: ' . $e->getMessage());
    }
}
    /**
     * Eliminar todos los turnos de un empleado en un mes/año
     */
   public function obtenerOCrearCuadro(int $idEmpleado, int $anio, int $mes): array
    {
        $cuadro = CtCuadro::where('id_empleado', $idEmpleado)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->first();

        if ($cuadro) {
            return [
                'id_cuadro' => $cuadro->id,
                'anio'      => $cuadro->anio,
                'mes'       => $cuadro->mes,
                'estado'    => $cuadro->estado,
            ];
        }

        $cuadro = CtCuadro::create([
            'id_empleado'  => $idEmpleado,
            'anio'         => $anio,
            'mes'          => $mes,
            'estado'       => 'borrador',
            'id_user_crea' => auth()->id(),
        ]);

        return [
            'id_cuadro' => $cuadro->id,
            'anio'      => $cuadro->anio,
            'mes'       => $cuadro->mes,
            'estado'    => $cuadro->estado,
        ];
    } 

    /**
     * Crear una nueva asignación
     */
    public function crearAsignacion(array $data): array
    {
        $asignacion = CtAsignacion::create($data);
        return $this->formatearAsignacion($asignacion);
    }

    /**
     * Actualizar una asignación existente
     */
    public function actualizarAsignacion(CtAsignacion $asignacion, array $data): array
    {
        $asignacion->update($data);
        return $this->formatearAsignacion($asignacion->fresh());
    }

    /**
     * Formatear asignación para respuesta
     */
    private function formatearAsignacion(CtAsignacion $a): array
    {
        return [
            'id'             => $a->id,
            'id_cuadro'      => $a->id_cuadro,
            'id_empleado'    => $a->id_empleado,
            'fecha'          => $a->fecha,
            'es_descanso'    => $a->es_descanso,
            'es_festivo'     => $a->es_festivo,
            'hora_inicio'    => $a->getHoraInicio(),
            'hora_fin'       => $a->getHoraFin(),
            'hora_inicio_2'  => $a->getHoraInicio2(),
            'hora_fin_2'     => $a->getHoraFin2(),
            'es_jornada_partida' => $a->esJornadaPartida(),
            'rangos'         => $a->getRangos(),
            'plantilla'      => $a->plantilla ? [
                'id'        => $a->plantilla->id,
                'nombre'    => $a->plantilla->nombre,
                'color_hex' => $a->plantilla->color_hex,
            ] : null,
            'observacion'    => $a->observacion,
        ];
    }

    /**
     * Obtener festivos de un año (público)
     */
    public function obtenerFestivosDelAnio(int $anio): array
    {
        return $this->obtenerFestivos($anio);
    }

    /**
     * Obtener festivos de un año
     */
    private function obtenerFestivos(int $anio): array
    {
        $inicioAnio = Carbon::createFromDate($anio, 1, 1)->startOfYear()->toDateString();
        $finAnio    = Carbon::createFromDate($anio, 12, 31)->endOfYear()->toDateString();
        
        $festivos = \App\Models\TalentoHumano\CuadroTurnos\CtFestivo::whereBetween('fecha', [$inicioAnio, $finAnio])->get();
        return $festivos->map(function ($f) {
            return [
                'fecha'  => $f->fecha,
                'nombre' => $f->nombre,
            ];
        })->toArray();
    }

    /**
     * Calcular totales de horas por categoría
     */
    private function calcularTotalesMes(array $turnos, array $festivos, string $fechaInicio, string $fechaFin): array
    {
        $total = 0;
        $normales = 0;
        $nocturnas = 0;
        $festivas = 0;
        $festivasNocturnas = 0;

        $festivosMapa = collect($festivos)->mapWithKeys(function ($f) {
            return [substr($f['fecha'], 0, 10) => true];
        });

        foreach ($turnos as $turno) {
            if ($turno['es_descanso'] || !$turno['hora_inicio']) continue;

            $horas = $this->calcularHoras($turno, $festivosMapa->has($turno['fecha']));
            
            $total += $horas['total'];
            $normales += $horas['normales'];
            $nocturnas += $horas['nocturnas'];
            $festivas += $horas['festivas'];
            $festivasNocturnas += $horas['festivas_nocturnas'];
        }

        return [
            'total'                => round($total, 2),
            'normales'             => round($normales, 2),
            'nocturnas'            => round($nocturnas, 2),
            'festivas'             => round($festivas, 2),
            'festivas_nocturnas'   => round($festivasNocturnas, 2),
        ];
    }

    /**
     * Desglose por día
     */
    private function desglosarPorDia(array $turnos, array $festivos, string $fechaInicio, string $fechaFin): array
    {
        $festivosMapa = collect($festivos)->mapWithKeys(function ($f) {
            return [substr($f['fecha'], 0, 10) => true];
        });

        $desglose = [];
        foreach ($turnos as $turno) {
            if (!isset($desglose[$turno['fecha']])) {
                $desglose[$turno['fecha']] = [
                    'normales'           => 0,
                    'nocturnas'          => 0,
                    'festivas'           => 0,
                    'festivas_nocturnas' => 0,
                    'rangos'             => [],
                ];
            }

            if ($turno['es_descanso'] || !$turno['hora_inicio']) continue;

            $horas = $this->calcularHoras($turno, $festivosMapa->has($turno['fecha']));
            $desglose[$turno['fecha']]['normales'] += $horas['normales'];
            $desglose[$turno['fecha']]['nocturnas'] += $horas['nocturnas'];
            $desglose[$turno['fecha']]['festivas'] += $horas['festivas'];
            $desglose[$turno['fecha']]['festivas_nocturnas'] += $horas['festivas_nocturnas'];
            $desglose[$turno['fecha']]['rangos'] = $turno['rangos'];
        }

        return $desglose;
    }

    /**
     * Calcular horas de un turno específico
     */
    private function calcularHoras(array $turno, bool $esFestivo): array
    {
        $horaInicio = $turno['hora_inicio'] ?? null;
        $horaFin = $turno['hora_fin'] ?? null;
        $horaInicio2 = $turno['hora_inicio_2'] ?? null;
        $horaFin2 = $turno['hora_fin_2'] ?? null;

        $horas = [
            'total'                => 0,
            'normales'             => 0,
            'nocturnas'            => 0,
            'festivas'             => 0,
            'festivas_nocturnas'   => 0,
        ];

        if ($horaInicio && $horaFin) {
            $h = $this->calcularHorasRango($horaInicio, $horaFin, $esFestivo);
            foreach ($h as $k => $v) {
                $horas[$k] += $v;
            }
        }

        if ($horaInicio2 && $horaFin2) {
            $h = $this->calcularHorasRango($horaInicio2, $horaFin2, $esFestivo);
            foreach ($h as $k => $v) {
                $horas[$k] += $v;
            }
        }

        $horas['total'] = $horas['normales'] + $horas['nocturnas'] + $horas['festivas'] + $horas['festivas_nocturnas'];

        return $horas;
    }

    /**
     * Calcular horas de un rango horario (considera nocturnas desde 19:00)
     */
    private function calcularHorasRango(string $inicio, string $fin, bool $esFestivo): array
    {
        $horas = [
            'normales'           => 0,
            'nocturnas'          => 0,
            'festivas'           => 0,
            'festivas_nocturnas' => 0,
        ];

        $inicioMinutos = $this->horaAMinutos($inicio);
        $finMinutos = $this->horaAMinutos($fin);

        if ($finMinutos <= $inicioMinutos) {
            $finMinutos += 24 * 60;
        }

        $totalMinutos = $finMinutos - $inicioMinutos;
        $horasTotal = $totalMinutos / 60;

        $nocturnasInicio = $this->horaAMinutos('19:00');

        if ($esFestivo) {
            if ($inicioMinutos >= $nocturnasInicio) {
                $horas['festivas_nocturnas'] = $horasTotal;
            } elseif ($finMinutos <= $nocturnasInicio) {
                $horas['festivas'] = $horasTotal;
            } else {
                $horasFestivas = ($nocturnasInicio - $inicioMinutos) / 60;
                $horasFestivasNocturnas = ($finMinutos - $nocturnasInicio) / 60;
                $horas['festivas'] = $horasFestivas;
                $horas['festivas_nocturnas'] = $horasFestivasNocturnas;
            }
        } else {
            if ($inicioMinutos >= $nocturnasInicio) {
                $horas['nocturnas'] = $horasTotal;
            } elseif ($finMinutos <= $nocturnasInicio) {
                $horas['normales'] = $horasTotal;
            } else {
                $horasNormales = ($nocturnasInicio - $inicioMinutos) / 60;
                $horasNocturnas = ($finMinutos - $nocturnasInicio) / 60;
                $horas['normales'] = $horasNormales;
                $horas['nocturnas'] = $horasNocturnas;
            }
        }

        return $horas;
    }

    /**
     * Convertir hora HH:MM a minutos
     */
    private function horaAMinutos(string $hora): int
    {
        [$h, $m] = explode(':', $hora);
        return (int) $h * 60 + (int) $m;
    }
}
