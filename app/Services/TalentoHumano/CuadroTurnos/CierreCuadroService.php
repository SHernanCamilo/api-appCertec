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
     * Verifica si una unidad funcional est+í bloqueada para un per+¡odo.
     * Revisa TANTO el bloqueo manual como el cierre autom+ítico por fecha.
     */
    public function estaBloqueado(int $idUnidad, int $anio, int $mes): bool
    {
        // 1. Bloqueo manual
        if (BloqueoCuadro::estaBloqueada($idUnidad, $anio, $mes)) {
            return true;
        }

        // 2. Cierre autom+ítico por fecha
        $parametro = ParametroCierreCuadro::vigente();
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
     * Verifica si un cuadro espec+¡fico est+í bloqueado.
     */
    public function cuadroEstaBloqueado(int $idCuadro): bool
    {
        $cuadro = CtCuadro::find($idCuadro);
        if (!$cuadro || !$cuadro->id_unidad_funcional) return false;

        return $this->estaBloqueado($cuadro->id_unidad_funcional, $cuadro->anio, $cuadro->mes);
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
            // Verificar si ya est+í bloqueada
            if (BloqueoCuadro::estaBloqueada($idUnidad, $anio, $mes)) {
                $yaEstaban[] = $idUnidad;
                continue;
            }

            // Buscar cuadro asociado
            $cuadro = CtCuadro::where('id_unidad_funcional', $idUnidad)
                ->where('anio', $anio)
                ->where('mes', $mes)
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
     * Ejecuta el cierre autom+ítico seg+¦n los par+ímetros configurados.
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

            // Determinar si ya pas+¦ la fecha/hora de cierre
            $fechaCierre = Carbon::create($ahora->year, $ahora->month, min($diaCierre, $ahora->daysInMonth));
            $fechaCierre->setTimeFromTimeString($horaCierre);

            if ($ahora->lt($fechaCierre)) {
                continue; // A+¦n no es hora de cerrar
            }

            // Determinar qu+® mes cerrar
            if ($parametro->aplica_mes_actual) {
                $anioCierre = $ahora->year;
                $mesCierre = $ahora->month;
            } else {
                $mesAnterior = $ahora->copy()->subMonth();
                $anioCierre = $mesAnterior->year;
                $mesCierre = $mesAnterior->month;
            }

            // Buscar cuadros abiertos del per+¡odo
            $cuadrosAbiertos = CtCuadro::where('anio', $anioCierre)
                ->where('mes', $mesCierre)
                ->whereNotNull('id_unidad_funcional')
                ->where('estado', '!=', 'cerrado')
                ->when($parametro->id_empresa, function ($q, $idEmpresa) {
                    $q->whereHas('unidadFuncional', fn($uq) => $uq->where('id_empresa', $idEmpresa));
                })
                ->get();

            foreach ($cuadrosAbiertos as $cuadro) {
                // Verificar que no est+® ya bloqueado
                if (BloqueoCuadro::estaBloqueada($cuadro->id_unidad_funcional, $anioCierre, $mesCierre)) {
                    continue;
                }

                BloqueoCuadro::create([
                    'id_cuadro'           => $cuadro->id,
                    'id_unidad_funcional' => $cuadro->id_unidad_funcional,
                    'anio'                => $anioCierre,
                    'mes'                 => $mesCierre,
                    'estado'              => 'bloqueado',
                    'bloqueado_en'        => now(),
                    'bloqueado_por'       => null, // Autom+ítico
                    'tipo_bloqueo'        => 'automatico',
                ]);

                $cuadro->update(['estado' => 'cerrado']);
                $totalBloqueadas++;
            }
        }

        Log::info('Cierre autom+ítico ejecutado', ['total_bloqueadas' => $totalBloqueadas]);

        return ['bloqueadas' => $totalBloqueadas];
    }

    /**
     * Obtiene el estado de todas las unidades para un per+¡odo.
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

        return $query->get()->toArray();
    }
}
