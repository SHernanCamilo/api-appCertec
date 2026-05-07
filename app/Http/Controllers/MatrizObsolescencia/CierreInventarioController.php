<?php

namespace App\Http\Controllers\MatrizObsolescencia;

use App\Http\Controllers\Controller;
use App\Jobs\EjecutarCierreInventarioJob;
use App\Models\MatrizObsolescencia\MatzobsCierre;
use App\Models\MatrizObsolescencia\MatzobsCierreConfig;
use App\Models\MatrizObsolescencia\MatzobsCierreDetalle;
use App\Services\CierreInventarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Controller: CierreInventarioController
 *
 * Endpoints:
 *   GET    /cierre-inventario                → index   (lista de cierres)
 *   POST   /cierre-inventario                → store   (crear + ejecutar cierre)
 *   GET    /cierre-inventario/{id}           → show    (detalle de un cierre)
 *   DELETE /cierre-inventario/{id}           → destroy (eliminar cierre)
 *   GET    /cierre-inventario/{id}/detalle   → detalle paginado del snapshot
 *   GET    /cierre-inventario/{id}/resumen-empresa → resumen por empresa
 *   GET    /cierre-inventario/comparar       → comparar dos cierres
 *   GET    /cierre-inventario/config         → leer configuración
 *   PUT    /cierre-inventario/config         → actualizar configuración
 */
class CierreInventarioController extends Controller
{
    public function __construct(
        private readonly CierreInventarioService $service
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // LISTA DE CIERRES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /cierre-inventario
     * Lista todos los cierres ordenados por fecha descendente.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = MatzobsCierre::orderByDesc('created_at');

            // Filtro opcional por estado
            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }

            // Filtro opcional por período
            if ($request->filled('periodo')) {
                $query->where('periodo', 'like', '%' . $request->periodo . '%');
            }

            $perPage = (int) $request->get('per_page', 20);
            $cierres = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $cierres->items(),
                'total'   => $cierres->total(),
                'per_page'     => $cierres->perPage(),
                'current_page' => $cierres->currentPage(),
                'last_page'    => $cierres->lastPage(),
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error al obtener los cierres', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREAR Y EJECUTAR CIERRE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /cierre-inventario
     * Crea el registro de cierre y lanza el Job de ejecución.
     *
     * Body JSON:
     *   nombre      string  requerido  "Cierre Q2 2026"
     *   periodo     string  opcional   "2026-Q2"
     *   descripcion string  opcional
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre'      => 'required|string|max:200',
            'periodo'     => 'nullable|string|max:20',
            'descripcion' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $user = Auth::user();

            // Verificar que no haya un cierre en proceso
            $enProceso = MatzobsCierre::where('estado', 'procesando')->exists();
            if ($enProceso) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya hay un cierre en proceso. Espera a que finalice antes de iniciar otro.',
                ], 409);
            }

            // Crear el registro de cierre en estado pendiente
            $cierre = MatzobsCierre::create([
                'nombre'        => $request->nombre,
                'periodo'       => $request->periodo,
                'descripcion'   => $request->descripcion,
                'estado'        => 'pendiente',
                'creado_por'    => $user?->id,
                'nombre_creador'=> $user?->name,
            ]);

            // Ejecutar el cierre directamente usando el servicio
            // Esto asegura que se ejecute inmediatamente sin depender de la cola
            try {
                $service = app(\App\Services\CierreInventarioService::class);
                $service->ejecutar($cierre->id);
            } catch (\Throwable $e) {
                Log::error('Error al ejecutar cierre desde controlador', [
                    'cierre_id' => $cierre->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            // Recargar para devolver el estado final
            $cierre->refresh();

            $statusCode = $cierre->estado === 'cerrado' ? 201 : 202;

            return response()->json([
                'success' => true,
                'message' => $cierre->estado === 'cerrado'
                    ? "Cierre '{$cierre->nombre}' ejecutado correctamente. {$cierre->total_activos} activos procesados."
                    : "Cierre '{$cierre->nombre}' creado. El proceso está en cola.",
                'data'    => $this->formatCierre($cierre),
            ], $statusCode);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error al crear el cierre', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DETALLE DE UN CIERRE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /cierre-inventario/{id}
     * Devuelve la cabecera del cierre con resumen estadístico.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $cierre = MatzobsCierre::findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $this->formatCierre($cierre),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Cierre no encontrado'], 404);
        } catch (\Throwable $e) {
            return $this->errorResponse('Error al obtener el cierre', $e);
        }
    }

    /**
     * GET /cierre-inventario/{id}/detalle
     * Devuelve el snapshot paginado de activos del cierre con filtros opcionales.
     *
     * Query params:
     *   empresa_id, sucursal_id, sede_id, estado_obsolescencia,
     *   search, per_page, page
     */
    public function detalle(Request $request, int $id): JsonResponse
    {
        try {
            $cierre = MatzobsCierre::findOrFail($id);

            $query = MatzobsCierreDetalle::where('cierre_id', $cierre->id);

            if ($request->filled('empresa_id')) {
                $query->where('id_empresa', $request->empresa_id);
            }
            if ($request->filled('sucursal_id')) {
                $query->where('id_sucursal', $request->sucursal_id);
            }
            if ($request->filled('sede_id')) {
                $query->where('id_sede', $request->sede_id);
            }
            if ($request->filled('estado_obsolescencia')) {
                $query->where('estado_obsolescencia', $request->estado_obsolescencia);
            }
            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('nombre_equipo', 'like', "%{$s}%")
                      ->orWhere('agente',       'like', "%{$s}%")
                      ->orWhere('serial',        'like', "%{$s}%")
                      ->orWhere('nombre_empresa','like', "%{$s}%");
                });
            }

            $perPage = (int) $request->get('per_page', 25);
            $result  = $query->orderByDesc('puntaje')->paginate($perPage);

            return response()->json([
                'success'      => true,
                'cierre'       => $cierre->only(['id', 'nombre', 'periodo', 'estado', 'created_at']),
                'data'         => $result->items(),
                'total'        => $result->total(),
                'per_page'     => $result->perPage(),
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Cierre no encontrado'], 404);
        } catch (\Throwable $e) {
            return $this->errorResponse('Error al obtener el detalle', $e);
        }
    }

    /**
     * GET /cierre-inventario/{id}/resumen-empresa
     * Resumen estadístico del cierre agrupado por empresa.
     */
    public function resumenPorEmpresa(int $id): JsonResponse
    {
        try {
            $cierre = MatzobsCierre::findOrFail($id);

            $resumen = $this->service->resumenPorEmpresa($cierre->id);

            return response()->json([
                'success' => true,
                'cierre'  => $cierre->only(['id', 'nombre', 'periodo', 'created_at']),
                'data'    => $resumen,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Cierre no encontrado'], 404);
        } catch (\Throwable $e) {
            return $this->errorResponse('Error al obtener el resumen', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPARACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /cierre-inventario/comparar?cierre_a=1&cierre_b=2
     * Devuelve la comparación estadística entre dos cierres.
     */
    public function comparar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cierre_a' => 'required|integer|exists:matzobs_cierres,id',
            'cierre_b' => 'required|integer|exists:matzobs_cierres,id|different:cierre_a',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Parámetros inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $comparacion = $this->service->compararCierres(
                (int) $request->cierre_a,
                (int) $request->cierre_b
            );

            return response()->json([
                'success' => true,
                'data'    => $comparacion,
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error al comparar los cierres', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ELIMINAR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * DELETE /cierre-inventario/{id}
     * Elimina un cierre y su detalle (cascade en BD).
     * No se puede eliminar un cierre en estado 'procesando'.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $cierre = MatzobsCierre::findOrFail($id);

            if ($cierre->estado === 'procesando') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar un cierre que está en proceso.',
                ], 409);
            }

            $nombre = $cierre->nombre;
            $cierre->delete(); // cascade elimina matzobs_cierre_detalle

            return response()->json([
                'success' => true,
                'message' => "Cierre '{$nombre}' eliminado correctamente.",
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'Cierre no encontrado'], 404);
        } catch (\Throwable $e) {
            return $this->errorResponse('Error al eliminar el cierre', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIGURACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /cierre-inventario/config
     * Devuelve la configuración actual del proceso de cierre.
     */
    public function getConfig(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => MatzobsCierreConfig::config(),
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse('Error al obtener la configuración', $e);
        }
    }

    /**
     * PUT /cierre-inventario/config
     * Actualiza la configuración del proceso de cierre.
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recalcular_antes_de_cerrar' => 'sometimes|boolean',
            'incluir_sin_puntaje'        => 'sometimes|boolean',
            'incluir_inactivos'          => 'sometimes|boolean',
            'notificar_al_cerrar'        => 'sometimes|boolean',
            'emails_notificacion'        => 'sometimes|nullable|string|max:1000',
            'max_cierres_a_conservar'    => 'sometimes|integer|min:0|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $config = MatzobsCierreConfig::config();
            $config->update(array_merge(
                $validator->validated(),
                ['modificado_por' => Auth::user()?->name ?? 'sistema']
            ));

            return response()->json([
                'success' => true,
                'message' => 'Configuración actualizada correctamente.',
                'data'    => $config->fresh(),
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error al actualizar la configuración', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Formatea el cierre para la respuesta JSON añadiendo accessors calculados.
     */
    private function formatCierre(MatzobsCierre $cierre): array
    {
        return array_merge($cierre->toArray(), [
            'porcentaje_optimo'    => $cierre->porcentaje_optimo,
            'porcentaje_obsoleto'  => $cierre->porcentaje_obsoleto,
            'duracion_formateada'  => $cierre->duracion_formateada,
            'en_progreso'          => $cierre->en_progreso,
        ]);
    }

    /**
     * Respuesta de error estándar.
     */
    private function errorResponse(string $mensaje, \Throwable $e): JsonResponse
    {
        Log::error($mensaje, ['error' => $e->getMessage()]);

        return response()->json([
            'success' => false,
            'message' => $mensaje,
            'error'   => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
        ], 500);
    }
}
