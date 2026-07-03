<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvRecepcion;
use App\Models\Inventory\InvRecepcionDetalle;
use App\Models\Inventory\InvSecuencia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvRecepcionService
{
    /**
     * Obtener historial de recepciones con filtros
     */
    public function getAll(array $filters = []): array
    {
        $query = InvRecepcion::with(['compra', 'recibidoPor']);

        if (!empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }
        
        if (!empty($filters['compra_id'])) {
            $query->where('compra_id', $filters['compra_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('numero_recepcion', 'LIKE', "%{$search}%")
                  ->orWhere('numero_orden_compra', 'LIKE', "%{$search}%")
                  ->orWhere('oc_indigo', 'LIKE', "%{$search}%");
            });
        }

        $query->orderBy('id', 'desc');

        $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 25;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        
        $total = $query->count();
        $recepciones = $query->offset($offset)->limit($limit)->get();

        return [
            'success' => true,
            'data'    => $recepciones,
            'meta'    => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Obtener una recepción específica por ID
     */
    public function getById(int $id): ?InvRecepcion
    {
        return InvRecepcion::with(['detalles', 'compra', 'recibidoPor'])->find($id);
    }

    /**
     * Crear una recepción técnica desde una Orden de Compra
     */
    public function store(array $data, int $userId): array
    {
        DB::beginTransaction();
        try {
            $compraId = $data['compra_id'] ?? null;
            $compra = InvOrdenCompra::find($compraId);

            if (!$compra) {
                return ['success' => false, 'message' => 'Orden de compra no encontrada'];
            }

            // Generar número de recepción (Ej: REC-2024-001)
            $anoActual = date('Y');
            $secuencia = InvSecuencia::firstOrCreate(
                ['tipo_documento' => 'RECEPCION', 'ano' => $anoActual],
                ['ultimo_numero' => 0]
            );
            $secuencia->increment('ultimo_numero');
            $numeroRecepcion = sprintf('REC-%s-%04d', $anoActual, $secuencia->ultimo_numero);

            // Calcular items totales a recepcionar
            $itemsToReceive = array_filter($data['items'] ?? [], function($item) {
                return isset($item['recibido']) && $item['recibido'] == 1;
            });

            // Crear la cabecera
            $recepcion = InvRecepcion::create([
                'numero_recepcion'    => $numeroRecepcion,
                'compra_id'           => $compra->id,
                'numero_orden_compra' => $compra->numero_orden_compra,
                'oc_indigo'           => $compra->oc_indigo ?? null,
                'fecha_recepcion'     => now(),
                'recibido_por'        => $userId,
                'total_items'         => count($itemsToReceive),
                'observaciones'       => $data['observaciones'] ?? null,
                'estado'              => 'RECEPCIONADO' // Estado inicial
            ]);

            $rejectedCount = 0;

            // Procesar los detalles
            foreach ($itemsToReceive as $item) {
                // Verificar si fue rechazado por concepto de recepción técnica
                $isRejected = isset($item['concepto_recepcion']) && strtolower($item['concepto_recepcion']) === 'rechazado';
                if ($isRejected) {
                    $rejectedCount++;
                }

                InvRecepcionDetalle::create([
                    'recepcion_id'               => $recepcion->id,
                    'pedido_detalle_id'          => $item['pedido_detalle_id'] ?? null,
                    'codigo_producto'            => $item['codigo_producto'] ?? null,
                    'producto_nombre'            => $item['producto_nombre'] ?? null,
                    'cantidad_solicitada'        => $item['cantidad_solicitada'] ?? 0,
                    'cantidad_recibida'          => $item['cantidad_recibida'] ?? 0,
                    'numero_lote'                => $item['numero_lote'] ?? null,
                    'fecha_vencimiento'          => $item['fecha_vencimiento'] ?? null,
                    
                    // Aspectos Técnicos e INVIMA
                    'codigo_sanitario'           => $item['codigo_sanitario'] ?? null,
                    'aspecto_cumple'             => $item['aspecto_cumple'] ?? null,
                    'embalaje_cumple'            => $item['embalaje_cumple'] ?? null,
                    'contenido_cumple'           => $item['contenido_cumple'] ?? null,
                    'cadena_frio_temperatura'    => $item['cadena_frio_temperatura'] ?? null,
                    'concepto_recepcion'         => $item['concepto_recepcion'] ?? null, // Ej: "aprobado", "rechazado", "cuarentena"
                    
                    // Medicamentos Vitales No Disponibles (MVD)
                    'es_medicamento_vital'       => !empty($item['es_medicamento_vital']) ? 1 : 0,
                    'mvd_ium'                    => $item['mvd_ium'] ?? null,
                    'mvd_solicitante'            => $item['mvd_solicitante'] ?? null,
                    'mvd_principio_activo'       => $item['mvd_principio_activo'] ?? null,
                    'mvd_forma_farmaceutica'     => $item['mvd_forma_farmaceutica'] ?? null,
                    'mvd_presentacion_comercial' => $item['mvd_presentacion_comercial'] ?? null,
                    'mvd_fecha_autorizacion'     => $item['mvd_fecha_autorizacion'] ?? null,
                    
                    'observaciones_recepcion'    => $item['observaciones_recepcion'] ?? null
                ]);
            }

            // Cambiar estado de la compra temporalmente, 
            // el confirmador final lo pasará a RECIBIDA_TOTAL / PARCIAL.
            if ($compra->estado === 'EN_TRANSITO' || $compra->estado === 'BORRADOR') {
                $compra->update(['estado' => 'EN_RECEPCION']);
            }

            DB::commit();

            $message = 'Recepción técnica guardada exitosamente. Nro: ' . $numeroRecepcion;
            if ($rejectedCount > 0) {
                $message .= ". Atención: $rejectedCount producto(s) fueron rechazados técnicamente.";
            }

            return [
                'success' => true,
                'message' => $message,
                'data'    => $recepcion->load('detalles')
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear recepción técnica: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear la recepción técnica',
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Confirmar definitivamente la recepción
     */
    public function confirmar(int $id, int $userId): array
    {
        $recepcion = InvRecepcion::find($id);

        if (!$recepcion) {
            return ['success' => false, 'message' => 'Recepción no encontrada'];
        }

        if ($recepcion->estado === 'CONFIRMADO') {
            return ['success' => false, 'message' => 'Esta recepción ya fue confirmada anteriormente'];
        }

        DB::beginTransaction();
        try {
            $recepcion->update([
                'estado' => 'CONFIRMADO'
            ]);

            // Actualizar la orden de compra si existe
            if ($recepcion->compra_id) {
                $compra = InvOrdenCompra::find($recepcion->compra_id);
                if ($compra) {
                    $compra->update(['estado' => 'RECIBIDA_TOTAL']);
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Recepción confirmada exitosamente',
                'data'    => $recepcion->fresh()
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al confirmar recepción: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al confirmar la recepción',
                'error'   => $e->getMessage()
            ];
        }
    }
}
