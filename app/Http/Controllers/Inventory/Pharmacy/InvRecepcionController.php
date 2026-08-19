<?php

namespace App\Http\Controllers\Inventory\Pharmacy;

use App\Http\Controllers\Controller;
use App\Services\Inventory\Pharmacy\InvRecepcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvRecepcionController extends Controller
{
    protected InvRecepcionService $service;

    public function __construct(InvRecepcionService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar recepciones
     * GET /api/inventario/recepciones
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search'    => $request->query('search'),
                'estado'    => $request->query('estado'),
                'status'    => $request->query('status'),
                'compra_id' => $request->query('compra_id'),
                'limit'     => $request->query('limit', 25),
                'offset'    => $request->query('offset', 0),
            ];

            $result = $this->service->getAll($filters);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las recepciones',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar recepción específica o detalles de OC para recepcionar
     * GET /api/inventario/recepciones/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Primero intentar como recepcion ID
            $recepcion = $this->service->getById((int) $id);

            if ($recepcion) {
                return response()->json([
                    'success' => true,
                    'data'    => $recepcion->detalles ?? $recepcion,
                ], 200);
            }

            // Si no existe como recepción, intentar como compra_id (para recepcionar)
            $compra = \App\Models\Inventory\InvOrdenCompra::with('detalles')->find((int) $id);
            if ($compra) {
                // Retornar los detalles de la OC formateados para el formulario de recepción
                $data = $compra->detalles->map(function ($d) {
                    return [
                        'pedido_detalle_id' => $d->pedido_detalle_id,
                        'codigo_producto' => $d->codigo_producto_indigo,
                        'producto_nombre' => $d->producto_nombre,
                        'cantidad_solicitada_compra' => $d->cantidad_solicitada_compra,
                        'precio_unitario_compra' => $d->precio_unitario_compra,
                        'proveedor' => $d->proveedor,
                        'estado' => $d->estado,
                    ];
                });

                return response()->json([
                    'success' => true,
                    'data'    => $data,
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Recepción u orden no encontrada',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la recepción',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear Recepción Técnica (Guardado inicial)
     * POST /api/inventario/recepciones
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'compra_id'           => 'required|exists:inv_ordenes_compra,id',
            'observaciones'       => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.codigo_producto'   => 'required_with:items|string',
            'items.*.cantidad_recibida' => 'required_with:items|numeric|min:0',
            // Opcionales pero importantes para la recepción técnica
            'items.*.concepto_recepcion' => 'nullable|string',
            'items.*.es_medicamento_vital' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $userId = auth()->user()->id ?? 1;
            $result = $this->service->store($request->all(), $userId);

            return response()->json($result, $result['success'] ? 201 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la recepción',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirmar llegada o recepción.
     * Si el ID es una OC → confirmar llegada (cambiar a en_sitio).
     * Si el ID es una recepción → confirmar la recepción técnica.
     * PATCH /api/inventario/recepciones/{id}/confirmar
     */
    public function confirmar(Request $request, string $id): JsonResponse
    {
        try {
            $userId = auth()->user()->id ?? 1;

            // Verificar si es una OC (confirmar llegada)
            $compra = \App\Models\Inventory\InvOrdenCompra::find((int) $id);
            if ($compra && in_array(strtolower($compra->estado), ['confirmado', 'en_transito'])) {
                $result = $this->service->confirmArrival((int) $id, $userId);
                return response()->json($result, $result['success'] ? 200 : 400);
            }

            // Si no es OC con estado para llegada, intentar confirmar recepción
            $result = $this->service->confirmar((int) $id, $userId);
            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
