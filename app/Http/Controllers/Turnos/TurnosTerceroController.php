<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Services\Turnos\EmpleadosUnidadService;
use App\Services\Turnos\AccessControlService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TurnosTerceroController extends Controller
{
    public function __construct(private EmpleadosUnidadService $empleadosService) {}

    /**
     * GET /turnos/unidades/{unidadId}/todos-empleados
     * Lista TODOS los empleados de una unidad (users + terceros juntos).
     */
    public function getEmpleadosPorUnidad(Request $request, int $unidadId): JsonResponse
    {
        $accessControl = new AccessControlService($request->user());

        if (!$accessControl->tieneAccesoUnidad($unidadId)) {
            return response()->json(['message' => 'Sin acceso a esta unidad.'], 403);
        }

        $empleados = $this->empleadosService->getEmpleadosPorUnidad($unidadId);

        return response()->json([
            'success'        => true,
            'data'           => $empleados,
            'total_users'    => $empleados->where('tipo', 'user')->count(),
            'total_terceros' => $empleados->where('tipo', 'tercero')->count(),
            'total'          => $empleados->count(),
        ]);
    }

    /**
     * GET /turnos/terceros/por-empresa/{empresaId}
     * Lista terceros del tenant para una empresa, con estado de mapeo.
     */
    public function getTercerosPorEmpresa(Request $request, int $empresaId): JsonResponse
    {
        $accessControl = new AccessControlService($request->user());

        if (!$accessControl->tieneAccesoEmpresa($empresaId)) {
            return response()->json(['message' => 'Sin acceso a esta empresa.'], 403);
        }

        $terceros = DB::table('config_person_tercero')
            ->where('id_empresa', $empresaId)
            ->where('estado', true)
            ->select('id', 'nombre', 'email', 'numero_identificacion', 'unidad')
            ->orderBy('nombre')
            ->get();

        $terceros = $terceros->map(function ($tercero) use ($empresaId) {
            $idUnidad = $this->empleadosService->resolverUnidadDesdeTextoTercero($tercero->unidad ?? '', $empresaId);
            $tercero->id_unidad_funcional = $idUnidad;
            $tercero->tiene_mapeo = !is_null($idUnidad);
            return $tercero;
        });

        return response()->json([
            'success'    => true,
            'data'       => $terceros,
            'con_mapeo'  => $terceros->where('tiene_mapeo', true)->count(),
            'sin_mapeo'  => $terceros->where('tiene_mapeo', false)->count(),
        ]);
    }

    /**
     * POST /turnos/unidades/{unidadId}/terceros
     * Asigna un tercero a una unidad funcional.
     */
    public function asignarTercero(Request $request, int $unidadId): JsonResponse
    {
        $request->validate(['id_tercero' => 'required|integer']);

        $accessControl = new AccessControlService($request->user());
        if (!$accessControl->tieneAccesoUnidad($unidadId)) {
            return response()->json(['message' => 'Sin acceso a esta unidad.'], 403);
        }

        $asignado = $this->empleadosService->asignarTerceroAUnidad($unidadId, $request->id_tercero);

        if (!$asignado) {
            return response()->json(['message' => 'El tercero ya está asignado a esta unidad.'], 422);
        }

        return response()->json(['message' => 'Tercero asignado correctamente.'], 201);
    }

    /**
     * DELETE /turnos/unidades/{unidadId}/terceros/{terceroId}
     * Desasigna un tercero de una unidad funcional.
     */
    public function desasignarTercero(Request $request, int $unidadId, int $terceroId): JsonResponse
    {
        $accessControl = new AccessControlService($request->user());
        if (!$accessControl->tieneAccesoUnidad($unidadId)) {
            return response()->json(['message' => 'Sin acceso a esta unidad.'], 403);
        }

        $eliminado = $this->empleadosService->desasignarTerceroDeUnidad($unidadId, $terceroId);

        if (!$eliminado) {
            return response()->json(['message' => 'No se encontró la asignación.'], 404);
        }

        return response()->json(['message' => 'Tercero desasignado correctamente.']);
    }

    /**
     * GET /turnos/mapeo-unidades/pendientes/{empresaId}
     * Lista unidades del tenant que aún no tienen mapeo.
     */
    public function getUnidadesSinMapeo(Request $request, int $empresaId): JsonResponse
    {
        $accessControl = new AccessControlService($request->user());
        if (!$accessControl->tieneAccesoEmpresa($empresaId)) {
            return response()->json(['message' => 'Sin acceso a esta empresa.'], 403);
        }

        $pendientes = $this->empleadosService->getUnidadesTerceroSinMapeo($empresaId);

        return response()->json([
            'success' => true,
            'data'    => $pendientes,
            'total'   => $pendientes->count(),
        ]);
    }

    /**
     * POST /turnos/mapeo-unidades
     * Guarda el mapeo entre un texto de unidad del tenant y una unidad funcional.
     */
    public function guardarMapeoUnidad(Request $request): JsonResponse
    {
        $request->validate([
            'unidad_tercero'      => 'required|string|max:255',
            'id_empresa'          => 'required|integer',
            'id_unidad_funcional' => 'required|integer|exists:config_unidades_funcionales,id',
        ]);

        $accessControl = new AccessControlService($request->user());
        if (!$accessControl->tieneAccesoEmpresa($request->id_empresa)) {
            return response()->json(['message' => 'Sin acceso a esta empresa.'], 403);
        }

        $guardado = $this->empleadosService->guardarMapeoUnidad(
            $request->unidad_tercero,
            $request->id_empresa,
            $request->id_unidad_funcional,
            $request->user()->id
        );

        if (!$guardado) {
            return response()->json(['message' => 'Error al guardar el mapeo.'], 500);
        }

        return response()->json(['message' => 'Mapeo guardado correctamente.'], 201);
    }
}
