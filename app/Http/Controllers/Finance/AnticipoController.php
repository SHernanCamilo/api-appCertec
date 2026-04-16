<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\AnticipoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controlador de Anticipos de Viaje.
 *
 * Gestiona solicitudes de anticipo, aprobaciones y cálculo de topes.
 */
class AnticipoController extends Controller
{
    public function __construct(
        private AnticipoService $anticipoService
    ) {}

    /**
     * Calcular topes de anticipo para un empleado y destino.
     *
     * POST /api/anticipos/calcular-topes
     */
    public function calcularTopes(Request $request): JsonResponse
    {
        $request->validate([
            'id_empleado' => 'required|integer|exists:config_person_tercero,id',
            'id_ciudad_destino' => 'required|integer|exists:anti_ciudades,id',
            'fecha_salida' => 'required|date',
            'fecha_regreso' => 'required|date|after_or_equal:fecha_salida',
        ]);

        $topes = $this->anticipoService->calcularTopes(
            $request->id_empleado,
            $request->id_ciudad_destino,
            $request->fecha_salida,
            $request->fecha_regreso
        );

        return response()->json([
            'success' => true,
            'data' => $topes,
        ]);
    }

    /**
     * Crear nueva solicitud de anticipo.
     *
     * POST /api/anticipos/solicitudes
     */
    public function crear(Request $request): JsonResponse
    {
        $request->validate([
            'id_empleado' => 'required|integer|exists:config_person_tercero,id',
            'id_sede_origen' => 'nullable|integer|exists:config_ubi_sede,id',
            'id_ciudad_destino' => 'required|integer|exists:anti_ciudades,id',
            'fecha_salida' => 'required|date',
            'fecha_regreso' => 'required|date|after_or_equal:fecha_salida',
            'motivo' => 'required|string|max:500',
            'cobertura' => 'required|in:nacional,internacional',
        ]);

        try {
            // Si no envían id_sede_origen, tomarlo del contexto del usuario
            $data = $request->all();
            if (empty($data['id_sede_origen'])) {
                $contexto = \App\Models\UsuarioContexto::obtenerContexto(auth()->user());
                $data['id_sede_origen'] = $contexto?->sede_id ?? $contexto?->sucursal_id;
            }
            $data['radicado_por'] = auth()->id();

            $solicitud = $this->anticipoService->crearSolicitud($data);

            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada exitosamente',
                'data' => $solicitud,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar solicitudes con filtros.
     *
     * GET /api/anticipos/solicitudes
     */
    public function listar(Request $request): JsonResponse
    {
        $filtros = $request->only([
            'estado',
            'id_empleado',
            'fecha_desde',
            'fecha_hasta',
        ]);

        $solicitudes = $this->anticipoService->listarSolicitudes($filtros);

        return response()->json([
            'success' => true,
            'data' => $solicitudes,
        ]);
    }

    /**
     * Ver detalle de una solicitud.
     *
     * GET /api/anticipos/solicitudes/{id}
     */
    public function ver(int $id): JsonResponse
    {
        try {
            $solicitud = \App\Models\Finance\AntiSolicitud::with([
                'empleado',
                'ciudadDestino',
                'sedeOrigen',
                'items.concepto',
                'items.regla',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $solicitud,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Solicitud no encontrada',
            ], 404);
        }
    }

    /**
     * Aprobar una solicitud.
     *
     * POST /api/anticipos/solicitudes/{id}/aprobar
     */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comentario' => 'nullable|string|max:500',
            'monto_autorizado' => 'nullable|numeric|min:0',
        ]);

        try {
            $solicitud = $this->anticipoService->aprobar(
                $id,
                auth()->id(),
                $request->comentario,
                $request->monto_autorizado
            );

            return response()->json([
                'success' => true,
                'message' => 'Solicitud aprobada exitosamente',
                'data' => $solicitud,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al aprobar solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rechazar una solicitud.
     *
     * POST /api/anticipos/solicitudes/{id}/rechazar
     */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comentario' => 'required|string|max:500',
        ]);

        try {
            $solicitud = $this->anticipoService->rechazar(
                $id,
                auth()->id(),
                $request->comentario
            );

            return response()->json([
                'success' => true,
                'message' => 'Solicitud rechazada',
                'data' => $solicitud,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al rechazar solicitud: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtener historial de aprobaciones de una solicitud.
     *
     * GET /api/anticipos/solicitudes/{id}/historial
     */
    public function historial(int $id): JsonResponse
    {
        try {
            // Asumiendo que id_solicitud = id_instancia (ajustar según diseño final)
            $historial = app(\App\Services\Workflow\WorkflowExecutor::class)
                ->obtenerHistorial($id);

            return response()->json([
                'success' => true,
                'data' => $historial,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // FASE POST-VIAJE
    // ========================================================================

    /**
     * Tesorería desembolsa el anticipo.
     * POST /api/anticipos/solicitudes/{id}/desembolsar
     */
    public function desembolsar(int $id): JsonResponse
    {
        try {
            $solicitud = $this->anticipoService->desembolsar($id, auth()->id());
            return response()->json(['success' => true, 'message' => 'Anticipo desembolsado', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Solicitante sube soportes y legaliza.
     * POST /api/anticipos/solicitudes/{id}/legalizar
     */
    public function legalizar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'monto_legalizado' => 'required|numeric|min:0',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        try {
            $solicitud = $this->anticipoService->legalizar($id, auth()->id(), $request->all());
            return response()->json(['success' => true, 'message' => 'Solicitud legalizada', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Contabilidad decide: aceptar | sobrante | excedente.
     * POST /api/anticipos/solicitudes/{id}/decidir-contabilidad
     */
    public function decidirContabilidad(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'decision' => 'required|in:aceptar,sobrante,excedente',
            'comentario' => 'nullable|string|max:500',
        ]);

        try {
            $solicitud = $this->anticipoService->decidirContabilidad(
                $id, auth()->id(), $request->decision, $request->comentario
            );
            return response()->json(['success' => true, 'message' => 'Decisión registrada', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Solicitante registra devolución del sobrante.
     * POST /api/anticipos/solicitudes/{id}/registrar-devolucion
     */
    public function registrarDevolucion(int $id): JsonResponse
    {
        try {
            $solicitud = $this->anticipoService->registrarDevolucion($id, auth()->id());
            return response()->json(['success' => true, 'message' => 'Devolución registrada', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Dir. Financiera aprueba reintegro del excedente.
     * POST /api/anticipos/solicitudes/{id}/aprobar-excedente
     */
    public function aprobarExcedente(Request $request, int $id): JsonResponse
    {
        try {
            $solicitud = $this->anticipoService->aprobarExcedente($id, auth()->id(), $request->comentario);
            return response()->json(['success' => true, 'message' => 'Excedente aprobado', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Dir. Financiera rechaza reintegro del excedente.
     * POST /api/anticipos/solicitudes/{id}/rechazar-excedente
     */
    public function rechazarExcedente(Request $request, int $id): JsonResponse
    {
        $request->validate(['comentario' => 'required|string|max:500']);

        try {
            $solicitud = $this->anticipoService->rechazarExcedente($id, auth()->id(), $request->comentario);
            return response()->json(['success' => true, 'message' => 'Excedente rechazado', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Contabilidad cierra la solicitud.
     * POST /api/anticipos/solicitudes/{id}/cerrar
     */
    public function cerrarSolicitud(Request $request, int $id): JsonResponse
    {
        try {
            $solicitud = $this->anticipoService->cerrar($id, auth()->id(), $request->comentario);
            return response()->json(['success' => true, 'message' => 'Solicitud cerrada', 'data' => $solicitud]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    // ========================================================================
    // CATÁLOGOS
    // ========================================================================

    /**
     * Obtener tipos de anticipo.
     *
     * GET /api/anticipos/tipos
     */
    public function tipos(): JsonResponse
    {
        $tipos = $this->anticipoService->obtenerTipos();

        return response()->json([
            'success' => true,
            'data' => $tipos,
        ]);
    }

    /**
     * Obtener clases por tipo.
     *
     * GET /api/anticipos/clases/{idTipo}
     */
    public function clases(int $idTipo): JsonResponse
    {
        $clases = $this->anticipoService->obtenerClasesPorTipo($idTipo);

        return response()->json([
            'success' => true,
            'data' => $clases,
        ]);
    }

    /**
     * Obtener modalidades por clase.
     *
     * GET /api/anticipos/modalidades/{idClase}
     */
    public function modalidades(int $idClase): JsonResponse
    {
        $modalidades = $this->anticipoService->obtenerModalidadesPorClase($idClase);

        return response()->json([
            'success' => true,
            'data' => $modalidades,
        ]);
    }

    /**
     * Obtener conceptos por modalidad.
     *
     * GET /api/anticipos/conceptos/{idModalidad}
     */
    public function conceptos(int $idModalidad): JsonResponse
    {
        $conceptos = $this->anticipoService->obtenerConceptosPorModalidad($idModalidad);

        return response()->json([
            'success' => true,
            'data' => $conceptos,
        ]);
    }

    /**
     * Obtener ciudades clasificadas.
     *
     * GET /api/anticipos/ciudades
     */
    public function ciudades(): JsonResponse
    {
        $ciudades = \App\Models\Finance\AntiCiudad::activas()
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $ciudades,
        ]);
    }
}
