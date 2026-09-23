<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

use App\Models\TalentoHumano\CuadroTurnos\BloqueoCuadro;
use App\Models\TalentoHumano\CuadroTurnos\ParametroCierreCuadro;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CierreCuadroService
{
    /**
     * Verifica si una unidad funcional est+� bloqueada para un per+�odo.
     * Revisa TANTO el bloqueo manual como el cierre autom+�tico por fecha.
     */
    public function estaBloqueado(int $idUnidad, int $anio, int $mes): bool
    {
        // 1. Bloqueo manual
        if (BloqueoCuadro::estaBloqueada($idUnidad, $anio, $mes)) {
            return true;
        }

        // 2. Cierre automatico por fecha — tomar el parametro DE LA EMPRESA de la unidad.
        //    (Los parametros de cierre son por empresa; sin este filtro se tomaba
        //     el de otra empresa y cerraba/abria incorrectamente.)
        $idEmpresa = \DB::table('config_unidades_funcionales')
            ->where('id', $idUnidad)
            ->value('id_empresa');

        // Sin empresa no hay parametro por empresa => no aplica cierre automatico.
        if (!$idEmpresa) {
            return false;
        }

        $parametro = ParametroCierreCuadro::vigente((int) $idEmpresa);
        if (!$parametro || $parametro->tipo_bloqueo !== 'automatico') {
            return false;
        }

        $ahora = now();

        if ($parametro->aplica_mes_actual) {
            $diaCierre = min($parametro->dia_cierre, \Carbon\Carbon::create($anio, $mes, 1)->daysInMonth);
            $fechaCierre = \Carbon\Carbon::create($anio, $mes, $diaCierre);
            $fechaCierre->setTimeFromTimeString($parametro->hora_cierre ?? '23:59');
        } else {
            $mesSiguiente = \Carbon\Carbon::create($anio, $mes, 1)->addMonth();
            $diaCierre = min($parametro->dia_cierre, $mesSiguiente->daysInMonth);
            $fechaCierre = \Carbon\Carbon::create($mesSiguiente->year, $mesSiguiente->month, $diaCierre);
            $fechaCierre->setTimeFromTimeString($parametro->hora_cierre ?? '23:59');
        }

        return $ahora->gt($fechaCierre);
    }

    /**
     * Verifica si un cuadro espec+�fico est+� bloqueado.
     */
    public function cuadroEstaBloqueado(int $idCuadro): bool
    {
        $cuadro = CtCuadro::with('grupo')->find($idCuadro);
        $idUnidad = $cuadro?->grupo?->id_unidad_funcional;
        if (!$cuadro || !$idUnidad) return false;

        return $this->estaBloqueado($idUnidad, $cuadro->anio, $cuadro->mes);
    }

    /**
     * Bloquear unidades funcionales manualmente.
     *
     * @param array $idsUnidades IDs de unidades a bloquear
     * @param int $anio
     * @param int $mes
     * @param int|null $userId Usuario que ejecuta
     * @return array Resultado con bloqueadas y errores
     */
    public function bloquearManual(array $idsUnidades, int $anio, int $mes, ?int $userId = null): array
    {
        $bloqueadas = [];
        $yaEstaban = [];

        foreach ($idsUnidades as $idUnidad) {
            // Verificar si ya est+� bloqueada
            if (BloqueoCuadro::estaBloqueada($idUnidad, $anio, $mes)) {
                $yaEstaban[] = $idUnidad;
                continue;
            }

            // Buscar cuadro asociado (la unidad se relaciona via el grupo)
            $cuadro = CtCuadro::where('anio', $anio)
                ->where('mes', $mes)
                ->whereHas('grupo', fn($gq) => $gq->where('id_unidad_funcional', $idUnidad))
                ->first();

            BloqueoCuadro::create([
                'id_cuadro'           => $cuadro?->id,
                'id_unidad_funcional' => $idUnidad,
                'anio'                => $anio,
                'mes'                 => $mes,
                'estado'              => 'bloqueado',
                'bloqueado_en'        => now(),
                'bloqueado_por'       => $userId,
                'tipo_bloqueo'        => 'manual',
            ]);

            // Actualizar estado del cuadro si existe
            if ($cuadro) {
                $cuadro->update(['estado' => 'cerrado']);
            }

            $bloqueadas[] = $idUnidad;
        }

        Log::info('Cierre manual de cuadros', [
            'usuario' => $userId,
            'periodo' => "{$mes}/{$anio}",
            'bloqueadas' => count($bloqueadas),
            'ya_estaban' => count($yaEstaban),
        ]);

        return [
            'bloqueadas'  => $bloqueadas,
            'ya_estaban'  => $yaEstaban,
            'total'       => count($idsUnidades),
        ];
    }

    /**
     * Reabre los cuadros cerrados AUTOMATICAMENTE de una empresa cuya nueva
     * fecha de cierre aun no ha llegado.
     *
     * Se llama al guardar el parametro de cierre: si el admin mueve el dia de
     * cierre hacia adelante (ej. de 22 a 24), los cuadros que se habian cerrado
     * por la fecha vieja se reabren. Los bloqueos MANUALES NO se tocan (fueron
     * decision humana). Solo aplica cuando el parametro es de tipo 'automatico'.
     *
     * @return int cantidad de cuadros reabiertos
     */
    public function reabrirAutomaticosPorNuevaFecha(int $idEmpresa): int
    {
        $parametro = ParametroCierreCuadro::vigente($idEmpresa);

        // Si no es automatico, no se reabre nada por fecha.
        if (!$parametro || $parametro->tipo_bloqueo !== 'automatico') {
            return 0;
        }

        // Bloqueos AUTOMATICOS activos de unidades de esta empresa.
        $bloqueos = BloqueoCuadro::where('estado', 'bloqueado')
            ->where('tipo_bloqueo', 'automatico')
            ->whereIn('id_unidad_funcional', function ($q) use ($idEmpresa) {
                $q->select('id')->from('config_unidades_funcionales')->where('id_empresa', $idEmpresa);
            })
            ->get();

        $reabiertos = 0;

        foreach ($bloqueos as $bloqueo) {
            // Calcular la fecha de cierre segun la NUEVA regla para el periodo del bloqueo.
            $fechaCierre = $this->calcularFechaCierre($parametro, $bloqueo->anio, $bloqueo->mes);

            // Si con la nueva fecha AUN no deberia estar cerrado => reabrir.
            if (now()->lte($fechaCierre)) {
                $bloqueo->update([
                    'estado'            => 'desbloqueado',
                    'desbloqueado_en'   => now(),
                    'desbloqueado_por'  => auth()->id(),
                    'motivo_desbloqueo' => 'Reapertura automatica: se movio la fecha de cierre',
                ]);

                if ($bloqueo->id_cuadro) {
                    CtCuadro::where('id', $bloqueo->id_cuadro)->update(['estado' => 'creado']);
                }

                $reabiertos++;
            }
        }

        if ($reabiertos > 0) {
            Log::info('Reapertura automatica por cambio de fecha de cierre', [
                'empresa'    => $idEmpresa,
                'reabiertos' => $reabiertos,
            ]);
        }

        return $reabiertos;
    }

    /**
     * Calcula la fecha/hora de cierre para un periodo segun un parametro.
     */
    private function calcularFechaCierre(ParametroCierreCuadro $parametro, int $anio, int $mes): \Carbon\Carbon
    {
        if ($parametro->aplica_mes_actual) {
            $dia = min($parametro->dia_cierre, \Carbon\Carbon::create($anio, $mes, 1)->daysInMonth);
            $fecha = \Carbon\Carbon::create($anio, $mes, $dia);
        } else {
            $mesSiguiente = \Carbon\Carbon::create($anio, $mes, 1)->addMonth();
            $dia = min($parametro->dia_cierre, $mesSiguiente->daysInMonth);
            $fecha = \Carbon\Carbon::create($mesSiguiente->year, $mesSiguiente->month, $dia);
        }
        return $fecha->setTimeFromTimeString($parametro->hora_cierre ?? '23:59');
    }

    /**
     * Desbloquear una unidad funcional.
     */
    public function desbloquear(int $idUnidad, int $anio, int $mes, int $userId, string $motivo): bool
    {
        $bloqueo = BloqueoCuadro::where('id_unidad_funcional', $idUnidad)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('estado', 'bloqueado')
            ->first();

        if (!$bloqueo) return false;

        $bloqueo->update([
            'estado'             => 'desbloqueado',
            'desbloqueado_en'    => now(),
            'desbloqueado_por'   => $userId,
            'motivo_desbloqueo'  => $motivo,
        ]);

        // Reabrir cuadro si existe
        if ($bloqueo->id_cuadro) {
            CtCuadro::where('id', $bloqueo->id_cuadro)->update(['estado' => 'creado']);
        }

        Log::info('Desbloqueo de cuadro', [
            'usuario'  => $userId,
            'unidad'   => $idUnidad,
            'periodo'  => "{$mes}/{$anio}",
            'motivo'   => $motivo,
        ]);

        return true;
    }

    /**
     * Ejecuta el cierre autom+�tico seg+�n los par+�metros configurados.
     * Se llama desde un cron job / scheduler.
     */
    public function ejecutarCierreAutomatico(): array
    {
        $parametros = ParametroCierreCuadro::where('activo', true)
            ->where('tipo_bloqueo', 'automatico')
            ->get();

        $totalBloqueadas = 0;

        foreach ($parametros as $parametro) {
            $ahora = now();
            $diaCierre = $parametro->dia_cierre;
            $horaCierre = $parametro->hora_cierre;

            // Determinar si ya pas+� la fecha/hora de cierre
            $fechaCierre = Carbon::create($ahora->year, $ahora->month, min($diaCierre, $ahora->daysInMonth));
            $fechaCierre->setTimeFromTimeString($horaCierre);

            if ($ahora->lt($fechaCierre)) {
                continue; // A+�n no es hora de cerrar
            }

            // Determinar qu+� mes cerrar
            if ($parametro->aplica_mes_actual) {
                $anioCierre = $ahora->year;
                $mesCierre = $ahora->month;
            } else {
                $mesAnterior = $ahora->copy()->subMonth();
                $anioCierre = $mesAnterior->year;
                $mesCierre = $mesAnterior->month;
            }

            // Buscar cuadros abiertos del per+�odo
            // La unidad funcional del cuadro se obtiene VIA el grupo
            // (CtCuadro -> grupo -> id_unidad_funcional). CtCuadro NO tiene
            // columna id_unidad_funcional ni relacion unidadFuncional().
            $cuadrosAbiertos = CtCuadro::with('grupo')
                ->where('anio', $anioCierre)
                ->where('mes', $mesCierre)
                ->where('estado', '!=', 'cerrado')
                ->whereHas('grupo', function ($gq) use ($parametro) {
                    $gq->whereNotNull('id_unidad_funcional')
                       ->when($parametro->id_empresa, fn($q, $idEmpresa) => $q->where('id_empresa', $idEmpresa));
                })
                ->get();

            foreach ($cuadrosAbiertos as $cuadro) {
                $idUnidad = $cuadro->grupo->id_unidad_funcional ?? null;
                if (!$idUnidad) {
                    continue;
                }

                // Verificar que no este ya bloqueado
                if (BloqueoCuadro::estaBloqueada($idUnidad, $anioCierre, $mesCierre)) {
                    continue;
                }

                BloqueoCuadro::create([
                    'id_cuadro'           => $cuadro->id,
                    'id_unidad_funcional' => $idUnidad,
                    'anio'                => $anioCierre,
                    'mes'                 => $mesCierre,
                    'estado'              => 'bloqueado',
                    'bloqueado_en'        => now(),
                    'bloqueado_por'       => null, // Automatico
                    'tipo_bloqueo'        => 'automatico',
                ]);

                $cuadro->update(['estado' => 'cerrado']);
                $totalBloqueadas++;
            }
        }

        Log::info('Cierre autom+�tico ejecutado', ['total_bloqueadas' => $totalBloqueadas]);

        return ['bloqueadas' => $totalBloqueadas];
    }

    /**
     * Obtiene el estado de todas las unidades para un per+�odo.
     */
    public function estadoUnidades(int $anio, int $mes, ?int $idEmpresa = null): array
    {
        $query = DB::table('config_unidades_funcionales as u')
            ->leftJoin('humtal_bloqueo_cuadro as b', function ($join) use ($anio, $mes) {
                $join->on('u.id', '=', 'b.id_unidad_funcional')
                    ->where('b.anio', $anio)
                    ->where('b.mes', $mes)
                    ->where('b.estado', 'bloqueado');
            })
            ->where('u.estado', true)
            ->select(
                'u.id',
                'u.codigo',
                'u.nombre',
                'u.id_empresa',
                DB::raw('CASE WHEN b.id IS NOT NULL THEN 1 ELSE 0 END as bloqueado'),
                'b.bloqueado_en',
                'b.bloqueado_por',
                'b.tipo_bloqueo'
            )
            ->orderBy('u.nombre');

        if ($idEmpresa) {
            $query->where('u.id_empresa', $idEmpresa);
        }

        // Filtrar por empresas habilitadas para el módulo
        $empresasHabilitadas = config('cuadro_turnos.empresas_habilitadas', []);
        if (!empty($empresasHabilitadas)) {
            $query->whereIn('u.id_empresa', $empresasHabilitadas);
        }

        return $query->get()->toArray();
    }
}
