<?php

namespace App\Services\Turnos;

use App\Models\Turnos\CtGrupo;
use App\Models\Turnos\CtGrupoEncargado;
use App\Models\Turnos\CtGrupoEmpleado;
use App\Models\Turnos\CtCuadro;
use App\Models\Turnos\CtAsignacion;
use App\Models\Turnos\CtPlantilla;
use App\Models\Turnos\CtNovedad;
use App\Models\Turnos\CtNovedadTipo;
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
                $data['id'] ?? null
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
                'id_plantilla'         => $idPlantilla,
                'es_descanso'          => $esDescanso,
                'es_festivo'           => $data['es_festivo'] ?? false,
                'hora_inicio_override' => $data['hora_inicio_override'] ?? null,
                'hora_fin_override'    => $data['hora_fin_override'] ?? null,
                'observacion'          => $data['observacion'] ?? null,
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
     * Lógica: dos turnos se solapan si inicio1 < fin2 AND fin1 > inicio2
     */
    public function validarSolapamiento(
        int $idEmpleado,
        string $fecha,
        ?int $idPlantilla,
        ?string $horaInicioOverride = null,
        ?string $horaFinOverride = null,
        ?int $excluirAsignacionId = null
    ): bool {
        if (!$idPlantilla) {
            return false; // Descanso, no hay solapamiento
        }

        // Obtener la plantilla nueva
        $plantillaNueva = CtPlantilla::find($idPlantilla);
        if (!$plantillaNueva) {
            return false;
        }

        $nuevaInicio = $horaInicioOverride ?? $plantillaNueva->hora_inicio;
        $nuevaFin    = $horaFinOverride    ?? $plantillaNueva->hora_fin;

        // Buscar todas las asignaciones del empleado en esa fecha (en cualquier cuadro)
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
            $existenteInicio = $asignacion->hora_inicio_override ?? $asignacion->plantilla?->hora_inicio;
            $existenteFin    = $asignacion->hora_fin_override    ?? $asignacion->plantilla?->hora_fin;

            if (!$existenteInicio || !$existenteFin) {
                continue;
            }

            // Dos turnos se solapan si: inicio1 < fin2 AND fin1 > inicio2
            if ($nuevaInicio < $existenteFin && $nuevaFin > $existenteInicio) {
                return true; // Hay solapamiento
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
                    'id'         => $asignacion->plantilla->id,
                    'codigo'     => $asignacion->plantilla->codigo,
                    'nombre'     => $asignacion->plantilla->nombre,
                    'hora_inicio' => $asignacion->getHoraInicio(),
                    'hora_fin'    => $asignacion->getHoraFin(),
                    'color_hex'  => $asignacion->plantilla->color_hex,
                ] : null,
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

        $asignaciones = CtAsignacion::with(['cuadro.grupo', 'plantilla'])
            ->where('id_empleado', $idEmpleado)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->orderBy('fecha')
            ->get();

        return $asignaciones->map(function ($a) {
            return [
                'id'           => $a->id,
                'fecha'        => Carbon::parse($a->fecha)->format('Y-m-d'),
                'es_descanso'  => $a->es_descanso,
                'es_festivo'   => $a->es_festivo,
                'hora_inicio'  => $a->getHoraInicio(),
                'hora_fin'     => $a->getHoraFin(),
                'plantilla'    => $a->plantilla ? [
                    'id'        => $a->plantilla->id,
                    'codigo'    => $a->plantilla->codigo,
                    'nombre'    => $a->plantilla->nombre,
                    'color_hex' => $a->plantilla->color_hex,
                ] : null,
                'grupo'        => $a->cuadro?->grupo ? [
                    'id'     => $a->cuadro->grupo->id,
                    'nombre' => $a->cuadro->grupo->nombre,
                ] : null,
                'observacion'  => $a->observacion,
            ];
        })->toArray();
    }
}
