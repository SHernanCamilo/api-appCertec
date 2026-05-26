<?php

namespace App\Http\Controllers\Config;

use App\Http\Controllers\Controller;
use App\Models\Config\SecSecuencia;
use App\Models\Config\SecDetalle;
use App\Services\SecuenciaNumericaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SecSecuenciaController extends Controller
{
    public function __construct(
        private readonly SecuenciaNumericaService $service
    ) {}

    /**
     * Listar secuencias con sus detalles.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = SecSecuencia::with([
                'modulo:id,nombre,codigo',
                'proceso:id,nombre,codigo',
                'detalles.patron',
                'detalles.sucursal:id,nombre',
                'detalles.sede:id,nombre',
            ]);

            if ($request->filled('empresa_id')) {
                $query->where('empresa_id', $request->empresa_id);
            }

            if ($request->filled('modulo_id')) {
                $query->where('modulo_id', $request->modulo_id);
            }

            if ($request->boolean('solo_activos', false)) {
                $query->activos();
            }

            $secuencias = $query->orderBy('id', 'desc')->get();

            return response()->json([
                'success' => true,
                'data'    => $secuencias,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las secuencias',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear cabecera de secuencia con sus detalles.
     *
     * Body esperado:
     * {
     *   "empresa_id": 1,
     *   "modulo_id": 5,
     *   "proceso_id": 12,
     *   "es_manual": false,
     *   "ambito": "sucursal",
     *   "es_secuencial": true,
     *   "rango": 4,
     *   "detalles": [
     *     { "patron_id": 3, "sucursal_id": 1, "siguiente_numero": 1 },
     *     { "patron_id": 4, "sucursal_id": 2, "siguiente_numero": 1 }
     *   ]
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'empresa_id'              => 'required|integer|exists:ent_empresas,id',
            'modulo_id'               => 'required|integer|exists:seg_modulos,id',
            'proceso_id'              => 'nullable|integer|exists:seg_modulos,id',
            'es_manual'               => 'nullable|boolean',
            'ambito'                  => 'required|in:empresa,sucursal,sede',
            'es_secuencial'           => 'nullable|boolean',
            'rango'                   => 'nullable|integer|min:1|max:20',
            'detalles'                => 'nullable|array',
            'detalles.*.patron_id'    => 'required|integer|exists:config_sec_patrones,id',
            'detalles.*.sucursal_id'  => 'nullable|integer|exists:config_ubi_sucursales,id',
            'detalles.*.sede_id'      => 'nullable|integer|exists:config_ubi_sede,id',
            'detalles.*.siguiente_numero' => 'nullable|integer|min:1',
        ], [
            'empresa_id.required'  => 'La empresa es obligatoria',
            'modulo_id.required'   => 'El módulo es obligatorio',
            'ambito.required'      => 'El ámbito es obligatorio',
            'ambito.in'            => 'El ámbito debe ser: empresa, sucursal o sede',
            'detalles.*.patron_id.required' => 'Cada detalle debe tener un patrón asignado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Verificar unicidad empresa + modulo + proceso
        $existe = SecSecuencia::where('empresa_id', $request->empresa_id)
            ->where('modulo_id', $request->modulo_id)
            ->where('proceso_id', $request->proceso_id)
            ->whereNull('deleted_at')
            ->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una secuencia configurada para este módulo/proceso en la empresa',
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, &$secuencia) {
                $secuencia = SecSecuencia::create([
                    'empresa_id'    => $request->empresa_id,
                    'modulo_id'     => $request->modulo_id,
                    'proceso_id'    => $request->proceso_id,
                    'es_manual'     => $request->input('es_manual', false),
                    'ambito'        => $request->ambito,
                    'es_secuencial' => $request->input('es_secuencial', true),
                    'rango'         => $request->input('rango', 4),
                    'estado'        => true,
                    'created_by'    => Auth::id(),
                ]);

                // Crear detalles si vienen en el request
                foreach ($request->input('detalles', []) as $det) {
                    SecDetalle::create([
                        'secuencia_id'    => $secuencia->id,
                        'patron_id'       => $det['patron_id'],
                        'sucursal_id'     => $det['sucursal_id'] ?? null,
                        'sede_id'         => $det['sede_id'] ?? null,
                        'siguiente_numero' => $det['siguiente_numero'] ?? 1,
                        'estado'          => true,
                        'created_by'      => Auth::id(),
                    ]);
                }
            });

            $secuencia->load([
                'modulo:id,nombre,codigo',
                'proceso:id,nombre,codigo',
                'detalles.patron',
                'detalles.sucursal:id,nombre',
                'detalles.sede:id,nombre',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Secuencia creada correctamente',
                'data'    => $secuencia,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la secuencia',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar una secuencia con sus detalles.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $secuencia = SecSecuencia::with([
                'modulo:id,nombre,codigo',
                'proceso:id,nombre,codigo',
                'detalles.patron',
                'detalles.sucursal:id,nombre',
                'detalles.sede:id,nombre',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $secuencia,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Secuencia no encontrada',
            ], 404);
        }
    }

    /**
     * Actualizar cabecera de secuencia.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $secuencia = SecSecuencia::find($id);

        if (!$secuencia) {
            return response()->json([
                'success' => false,
                'message' => 'Secuencia no encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'es_manual'     => 'nullable|boolean',
            'ambito'        => 'sometimes|required|in:empresa,sucursal,sede',
            'es_secuencial' => 'nullable|boolean',
            'rango'         => 'nullable|integer|min:1|max:20',
            'estado'        => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $secuencia->update($request->only([
                'es_manual', 'ambito', 'es_secuencial', 'rango', 'estado',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Secuencia actualizada correctamente',
                'data'    => $secuencia->fresh([
                    'modulo:id,nombre,codigo',
                    'proceso:id,nombre,codigo',
                    'detalles.patron',
                ]),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la secuencia',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar (soft delete) una secuencia y sus detalles.
     */
    public function destroy(int $id): JsonResponse
    {
        $secuencia = SecSecuencia::find($id);

        if (!$secuencia) {
            return response()->json([
                'success' => false,
                'message' => 'Secuencia no encontrada',
            ], 404);
        }

        try {
            DB::transaction(function () use ($secuencia) {
                $secuencia->detalles()->delete();
                $secuencia->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Secuencia eliminada correctamente',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la secuencia',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Previsualizar el próximo consecutivo sin incrementar el contador.
     *
     * GET /config/secuencias/previsualizar?empresa_id=1&modulo_id=5&proceso_id=12&unidad_id=2
     */
    public function previsualizar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'empresa_id' => 'required|integer',
            'modulo_id'  => 'required|integer',
            'proceso_id' => 'nullable|integer',
            'unidad_id'  => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $consecutivo = $this->service->previsualizar(
                (int) $request->empresa_id,
                (int) $request->modulo_id,
                $request->proceso_id ? (int) $request->proceso_id : null,
                $request->unidad_id  ? (int) $request->unidad_id  : null,
            );

            return response()->json([
                'success'     => true,
                'consecutivo' => $consecutivo,
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al previsualizar el consecutivo',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
