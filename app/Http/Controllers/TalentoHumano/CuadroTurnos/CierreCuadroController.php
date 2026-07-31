<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\ParametroCierreCuadro;
use App\Models\TalentoHumano\CuadroTurnos\BloqueoCuadro;
use App\Services\TalentoHumano\CuadroTurnos\CierreCuadroService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CierreCuadroController extends Controller
{
    private CierreCuadroService $service;

    public function __construct(CierreCuadroService $service)
    {
        $this->service = $service;
    }

    // ÔöÇÔöÇÔöÇ PAR+üMETROS DE CIERRE ÔöÇÔöÇÔöÇ

    /**
     * GET /api/turnos/cierre-cuadro/parametros
     */
    public function parametros(): JsonResponse
    {
        $parametros = ParametroCierreCuadro::orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'data' => $parametros]);
    }

    /**
     * POST /api/turnos/cierre-cuadro/parametros
     */
    public function guardarParametro(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_bloqueo'     => 'required|in:automatico,manual',
            'tipo_nomina'      => 'required|in:mensual,quincenal',
            'dia_cierre'       => 'required|integer|min:1|max:31',
            'hora_cierre'      => 'required',
            'aplica_mes_actual' => 'nullable|boolean',
            'id_empresa'       => 'nullable|integer',
        ]);

        $parametro = ParametroCierreCuadro::updateOrCreate(
            ['id_empresa' => $data['id_empresa'] ?? null, 'activo' => true],
            $data
        );

        return response()->json(['success' => true, 'data' => $parametro, 'message' => 'Par+ímetro guardado.']);
    }

    // ÔöÇÔöÇÔöÇ ESTADO DE UNIDADES ÔöÇÔöÇÔöÇ

    /**
     * GET /api/turnos/cierre-cuadro/estado?anio=X&mes=Y&id_empresa=Z
     * Lista unidades con su estado (bloqueado/abierto) para un per+¡odo.
     */
    public function estado(Request $request): JsonResponse
    {
        $request->validate([
            'anio' => 'required|integer|min:2020|max:2100',
            'mes'  => 'required|integer|min:1|max:12',
        ]);

        $anio = (int) $request->query('anio');
        $mes = (int) $request->query('mes');
        $idEmpresa = $request->query('id_empresa') ? (int) $request->query('id_empresa') : null;

        $estado = $this->service->estadoUnidades($anio, $mes, $idEmpresa);

        return response()->json(['success' => true, 'data' => $estado]);
    }

    // ÔöÇÔöÇÔöÇ BLOQUEAR/DESBLOQUEAR ÔöÇÔöÇÔöÇ

    /**
     * POST /api/turnos/cierre-cuadro/bloquear
     * Body: { ids_unidades: [1,2,3], anio, mes }
     */
    public function bloquear(Request $request): JsonResponse
    {
        $request->validate([
            'ids_unidades'   => 'required|array|min:1',
            'ids_unidades.*' => 'integer',
            'anio'           => 'required|integer|min:2020|max:2100',
            'mes'            => 'required|integer|min:1|max:12',
        ]);

        $resultado = $this->service->bloquearManual(
            $request->input('ids_unidades'),
            (int) $request->input('anio'),
            (int) $request->input('mes'),
            auth()->id()
        );

        $msg = count($resultado['bloqueadas']) . ' unidades bloqueadas';
        if (count($resultado['ya_estaban'])) {
            $msg .= ', ' . count($resultado['ya_estaban']) . ' ya estaban cerradas';
        }

        return response()->json(['success' => true, 'data' => $resultado, 'message' => $msg]);
    }

    /**
     * POST /api/turnos/cierre-cuadro/desbloquear
     * Body: { id_unidad, anio, mes, motivo }
     */
    public function desbloquear(Request $request): JsonResponse
    {
        $request->validate([
            'id_unidad' => 'required|integer',
            'anio'      => 'required|integer|min:2020|max:2100',
            'mes'       => 'required|integer|min:1|max:12',
            'motivo'    => 'required|string|max:255',
        ]);

        $ok = $this->service->desbloquear(
            (int) $request->input('id_unidad'),
            (int) $request->input('anio'),
            (int) $request->input('mes'),
            auth()->id(),
            $request->input('motivo')
        );

        if (!$ok) {
            return response()->json(['success' => false, 'message' => 'No se encontr+¦ bloqueo activo para esta unidad/per+¡odo.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Unidad desbloqueada correctamente.']);
    }

    /**
     * POST /api/turnos/cierre-cuadro/ejecutar-automatico
     * Ejecuta el cierre autom+ítico manualmente (para testing o cron).
     */
    public function ejecutarAutomatico(): JsonResponse
    {
        $resultado = $this->service->ejecutarCierreAutomatico();
        return response()->json(['success' => true, 'data' => $resultado, 'message' => "{$resultado['bloqueadas']} cuadros cerrados."]);
    }

    /**
     * GET /api/turnos/cierre-cuadro/historial?anio=X&mes=Y
     * Historial de bloqueos/desbloqueos.
     */
    public function historial(Request $request): JsonResponse
    {
        $query = BloqueoCuadro::with(['unidadFuncional'])
            ->orderByDesc('bloqueado_en');

        if ($request->filled('anio') && $request->filled('mes')) {
            $query->porPeriodo((int) $request->anio, (int) $request->mes);
        }

        if ($request->filled('id_unidad')) {
            $query->where('id_unidad_funcional', (int) $request->id_unidad);
        }

        return response()->json(['success' => true, 'data' => $query->limit(100)->get()]);
    }

    /**
     * GET /api/turnos/cierre-cuadro/verificar?id_unidad=X&anio=Y&mes=Z
     * Verifica si una unidad est+í bloqueada (+¦til para el frontend antes de editar).
     */
    public function verificar(Request $request): JsonResponse
    {
        $request->validate([
            'id_unidad' => 'required|integer',
            'anio'      => 'required|integer',
            'mes'       => 'required|integer',
        ]);

        $bloqueado = $this->service->estaBloqueado(
            (int) $request->query('id_unidad'),
            (int) $request->query('anio'),
            (int) $request->query('mes')
        );

        return response()->json(['success' => true, 'data' => ['bloqueado' => $bloqueado]]);
    }
}
