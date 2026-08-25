<?php

namespace App\Services\Inventory\Pharmacy;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvRecepcion;
use App\Models\Inventory\InvRecepcionDetalle;
use App\Models\Inventory\InvPedidoDetalle;
use App\Services\Inventory\FabricInventoryService;
use App\Services\Inventory\Pharmacy\InvSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvRecepcionService
{
    public function __construct(
        protected InvSequenceService $sequenceService,
        protected PharmacyService $pharmacyService,
        protected FabricInventoryService $fabricService,
    ) {}
    /**
     * Obtener historial de recepciones o compras pendientes de recepción.
     * Si se envía status con estados de OC (confirmado, en_sitio, parcial),
     * devuelve las OC listas para recepción.
     * Si se envía status con estados de recepción (RECEPCIONADO, CONFIRMADO),
     * devuelve recepciones históricas.
     */
    public function getAll(array $filters = []): array
    {
        $status = $filters['status'] ?? $filters['estado'] ?? '';

        // Si el status contiene estados de OC, devolver compras pendientes de recepción
        $ocStates = ['confirmado', 'en_sitio', 'parcial', 'en_transito'];
        $requestedStates = array_map('trim', explode(',', strtolower($status)));
        $isOcQuery = !empty($status) && count(array_intersect($requestedStates, $ocStates)) > 0;

        if ($isOcQuery) {
            return $this->getOrdenesPendientesRecepcion($requestedStates, $filters);
        }

        // Caso default: listar recepciones históricas
        $query = InvRecepcion::with(['compra', 'recibidoPor']);

        if (!empty($status)) {
            $query->whereIn('estado', $requestedStates);
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
     * Obtener OCs pendientes de recepción técnica.
     * Retorna las órdenes de compra con estructura compatible con la vista de recepciones.
     */
    private function getOrdenesPendientesRecepcion(array $estados, array $filters): array
    {
        $query = InvOrdenCompra::with(['detalles'])
            ->whereIn('estado', $estados);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('numero_orden_compra', 'LIKE', "%{$search}%")
                  ->orWhere('oc_indigo', 'LIKE', "%{$search}%")
                  ->orWhere('proveedor_nombre', 'LIKE', "%{$search}%");
            });
        }

        $query->orderBy('id', 'desc');

        $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 25;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $total = $query->count();
        $ordenes = $query->offset($offset)->limit($limit)->get();

        // Mapear a estructura compatible con la vista de recepciones
        $data = $ordenes->map(function ($oc) {
            $totalItems = $oc->detalles->count();
            // Items recibidos = que ya tienen recepción asociada
            $itemsRecibidos = DB::table('inv_recepcion_detalles as rd')
                ->join('inv_recepciones as r', 'r.id', '=', 'rd.recepcion_id')
                ->where('r.compra_id', $oc->id)
                ->distinct('rd.codigo_producto')
                ->count('rd.codigo_producto');

            return [
                'id' => $oc->id,
                'compra_id' => $oc->id,
                'numero_orden_compra' => $oc->numero_orden_compra,
                'oc_indigo' => $oc->oc_indigo,
                'fecha_orden' => $oc->fecha_orden,
                'proveedor_nombre' => $oc->proveedor_nombre,
                'estado' => $oc->estado,
                'creado_por_nombre' => $oc->creado_por_nombre,
                'total_items' => $totalItems,
                'items_recibidos' => $itemsRecibidos,
            ];
        });

        return [
            'success' => true,
            'data'    => $data,
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
     * Ítems de una OC listos para recepción técnica (vista_inv_compras_recepcion + muestreo).
     * Equivalente a digipharma.vista_compras_recepcion + cálculo formula_magistral_muestra.
     */
    public function getItemsForReception(int $compraId): array
    {
        $compra = InvOrdenCompra::find($compraId);
        if (!$compra) {
            return ['success' => false, 'message' => 'Orden de compra no encontrada'];
        }

        $rows = $this->queryComprasRecepcionView($compraId);

        $items = collect($rows)->map(function ($row) {
            return $this->mapRowToRecepcionItem($row);
        })->values()->all();

        $items = $this->enrichWithExternalProductData($items);

        return [
            'success' => true,
            'orden_numero' => $compra->numero_orden_compra,
            'proveedor' => $compra->proveedor_nombre,
            'oc_indigo' => $compra->oc_indigo,
            'estado_compra' => $compra->estado,
            'data' => $items,
        ];
    }

    /**
     * Consulta la vista SQL o fallback con JOINs equivalentes.
     */
    private function queryComprasRecepcionView(int $compraId): array
    {
        try {
            return DB::table('vista_inv_compras_recepcion')
                ->where('compra_id', $compraId)
                ->orderBy('detalle_id')
                ->get()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[INV-RECEPCION] Vista no disponible, usando JOIN directo: ' . $e->getMessage());

            return DB::table('inv_orden_compra_detalles as cd')
                ->join('inv_ordenes_compra as c', 'c.id', '=', 'cd.compra_id')
                ->leftJoin('inv_pedido_detalles as pd', 'pd.id', '=', 'cd.pedido_detalle_id')
                ->leftJoin('inv_pedidos as p', 'p.id', '=', 'pd.pedido_id')
                ->where('cd.compra_id', $compraId)
                ->orderBy('cd.id')
                ->select([
                    'cd.id as detalle_id',
                    'cd.compra_id',
                    'cd.pedido_detalle_id',
                    'c.numero_orden_compra',
                    'c.oc_indigo',
                    'c.fecha_orden',
                    'c.estado as estado_compra',
                    'c.proveedor_nombre',
                    'pd.pedido_id',
                    'p.numero_pedido',
                    'p.estado as estado_pedido',
                    DB::raw('COALESCE(pd.codigo_producto, cd.codigo_producto_indigo) as codigo_producto'),
                    DB::raw('COALESCE(pd.producto_nombre, cd.producto_nombre) as producto_nombre'),
                    'pd.producto_tipo',
                    DB::raw('pd.producto_marca as marca'),
                    'cd.cantidad_solicitada_compra',
                    DB::raw('pd.cantidad_solicitada as cantidad_solicitada_pedido'),
                    'pd.cantidad_recibida',
                    DB::raw('COALESCE(cd.proveedor, c.proveedor_nombre) as proveedor'),
                    'cd.clasificacion_vie',
                    'cd.clasificacion_venta',
                    'cd.precio_unitario_compra',
                    'cd.fecha_entrega_estimada',
                    DB::raw('cd.observaciones as observaciones_compra'),
                    DB::raw('cd.estado as estado_detalle_compra'),
                    'pd.numero_lote',
                    'pd.fecha_vencimiento',
                    'pd.cum_recibido',
                    'pd.codigo_sanitario',
                    'pd.aspecto_cumple',
                    'pd.embalaje_cumple',
                    'pd.contenido_cumple',
                    'pd.cadena_frio_temperatura',
                    'pd.concepto_recepcion',
                    DB::raw('pd.observaciones as observaciones_pedido'),
                    DB::raw('pd.estado as estado_detalle_pedido'),
                ])
                ->get()
                ->all();
        }
    }

    private function mapRowToRecepcionItem(object $row): array
    {
        $cantidad = (int) round((float) ($row->cantidad_solicitada_compra ?? 0));
        $codigo = (string) ($row->codigo_producto ?? '');
        $muestra = $this->pharmacyService->calcularMuestra($cantidad, $codigo);
        $clasificacion = strtoupper((string) ($row->clasificacion_vie ?? ''));

        return [
            'detalle_id' => $row->detalle_id,
            'pedido_detalle_id' => $row->pedido_detalle_id,
            'numero_pedido' => $row->numero_pedido ?? null,
            'codigo_producto' => $codigo,
            'producto_nombre' => $row->producto_nombre ?? '',
            'marca' => $row->marca ?? '',
            'tipo_producto' => $row->producto_tipo ?? 'Medicamento',
            'forma_farmaceutica' => '',
            'concentracion' => '',
            'unidad_empaque' => '',
            'cum_recibido' => $row->cum_recibido ?? '',
            'cantidad_solicitada_compra' => $row->cantidad_solicitada_compra,
            'cantidad_solicitada' => $cantidad,
            'cantidad_recibida' => $row->cantidad_recibida ?? 0,
            'precio_unitario_compra' => $row->precio_unitario_compra,
            'proveedor' => $row->proveedor ?? null,
            'clasificacion_vie' => $row->clasificacion_vie ?? null,
            'estado' => $row->estado_detalle_compra ?? null,
            'numero_lote' => $row->numero_lote ?? '',
            'fecha_vencimiento' => $row->fecha_vencimiento ?? '',
            'codigo_sanitario' => $row->codigo_sanitario ?? '',
            'es_medicamento_vital' => str_contains($clasificacion, 'VITAL'),
            'muestra_poblacion' => $muestra['tamano_muestra'],
            'muestra_exclusion' => !empty($muestra['inspeccion_total']) ? 1 : 0,
            'muestra_info' => $muestra,
        ];
    }

    /**
     * Enriquecer ítems con catálogo Fabric (in.Inventory_Productos).
     * Equivalente a legacy ReceptionService::enrichWithExternalProductData().
     */
    private function enrichWithExternalProductData(array $items): array
    {
        if (empty($items)) {
            return $items;
        }

        $codes = array_unique(array_filter(array_column($items, 'codigo_producto')));
        if (empty($codes)) {
            return $items;
        }

        try {
            $externalMap = $this->fabricService->findByCodes($codes);
        } catch (\Throwable $e) {
            Log::warning('[INV-RECEPCION] Error enriqueciendo productos Fabric: ' . $e->getMessage());
            return $items;
        }

        foreach ($items as &$item) {
            $code = $item['codigo_producto'] ?? '';
            $ext = $externalMap[$code] ?? null;
            if (!$ext) {
                continue;
            }
            $item = $this->applyExternalProductFields($item, $ext);
        }
        unset($item);

        return $items;
    }

    private function applyExternalProductFields(array $item, array $ext): array
    {
        $tipo = strtolower((string) ($item['tipo_producto'] ?? ''));
        $isDispositivo = str_contains($tipo, 'dispositivo') || str_contains($tipo, 'device');

        if ($isDispositivo) {
            $serie = trim((string) ($ext['serie'] ?? ''));
            $noManeja = $serie === ''
                || str_contains(strtolower($serie), 'no')
                || str_contains(strtolower($serie), 'no maneja');
            $item['forma_farmaceutica'] = $noManeja
                ? ($ext['descripcion'] ?? '')
                : $serie;
            $item['concentracion'] = $ext['risk_type'] ?? '';
        } else {
            $item['forma_farmaceutica'] = $ext['presentation'] ?? '';
            $item['concentracion'] = $ext['concentracion'] ?? '';
        }

        $item['unidad_empaque'] = $ext['unidad_empaque'] ?? '';
        if (empty($item['marca']) && !empty($ext['marca'])) {
            $item['marca'] = $ext['marca'];
        }

        return $item;
    }

    private function resolveMuestraPoblacion(array $item): ?int
    {
        if (isset($item['muestra_poblacion']) && $item['muestra_poblacion'] !== '') {
            return (int) $item['muestra_poblacion'];
        }

        $cantidad = (int) round((float) ($item['cantidad_recibida'] ?? $item['cantidad_solicitada'] ?? 0));
        $codigo = (string) ($item['codigo_producto'] ?? '');

        if ($cantidad <= 0 || $codigo === '') {
            return null;
        }

        return $this->pharmacyService->calcularMuestra($cantidad, $codigo)['tamano_muestra'];
    }

    /**
     * Listar compras que están listas para recepción (estados confirmado o en_sitio)
     */
    public function getPurchasesForReception(): array
    {
        $compras = InvOrdenCompra::with('detalles')
                    ->whereIn('estado', ['confirmado', 'en_sitio', 'EN_RECEPCION'])
                    ->orderBy('id', 'desc')
                    ->get();
        return ['success' => true, 'data' => $compras];
    }

    /**
     * Marcar llegada física al muelle (en_sitio)
     */
    public function confirmArrival(int $compraId, int $userId): array
    {
        $compra = InvOrdenCompra::find($compraId);
        if (!$compra) {
            return ['success' => false, 'message' => 'Compra no encontrada'];
        }
        
        if (!in_array(strtolower($compra->estado), ['confirmado', 'en_transito'])) {
             return ['success' => false, 'message' => 'La compra no está confirmada ni en tránsito'];
        }

        $compra->update(['estado' => 'en_sitio']);
        
        return ['success' => true, 'message' => 'Llegada al almacén confirmada', 'data' => $compra];
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
            $numeroRecepcion = $this->sequenceService->generateSequence('INVENTARIO', $userId, 'RECEPCION');

            // Calcular items totales a recepcionar
            $itemsToReceive = array_filter($data['items'] ?? [], function ($item) {
                $recibido = $item['recibido'] ?? false;
                $cantidad = (float) ($item['cantidad_recibida'] ?? 0);
                return ($recibido === true || $recibido === 1 || $recibido === '1') && $cantidad > 0;
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
                    'marca'                      => $item['marca'] ?? null,
                    'tipo_producto'              => $item['tipo_producto'] ?? null,
                    'forma_farmaceutica'         => $item['forma_farmaceutica'] ?? null,
                    'concentracion'              => $item['concentracion'] ?? null,
                    'unidad_empaque'             => $item['unidad_empaque'] ?? null,
                    'cantidad_solicitada'        => $item['cantidad_solicitada'] ?? 0,
                    'cantidad_recibida'          => $item['cantidad_recibida'] ?? 0,
                    'muestra_poblacion'          => $this->resolveMuestraPoblacion($item),
                    'numero_lote'                => $item['numero_lote'] ?? null,
                    'fecha_vencimiento'          => $item['fecha_vencimiento'] ?? null,
                    
                    // INVIMA y aspectos técnicos
                    'codigo_sanitario'           => $item['codigo_sanitario'] ?? null,
                    'fabricante'                 => $item['fabricante'] ?? null,
                    'vida_util'                  => $item['vida_util'] ?? null,
                    'estado_invima'              => $item['estado_invima'] ?? null,
                    'invima_override_manual'     => !empty($item['invima_override_manual']) ? 1 : 0,
                    'aspecto_cumple'             => $item['aspecto_cumple'] ?? null,
                    'embalaje_cumple'            => $item['embalaje_cumple'] ?? null,
                    'contenido_cumple'           => $item['contenido_cumple'] ?? null,
                    'cadena_frio_temperatura'    => $item['cadena_frio_temperatura'] ?? null,
                    'concepto_recepcion'         => $item['concepto_recepcion'] ?? null,
                    
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

                if (!empty($item['pedido_detalle_id'])) {
                    $pedidoUpdates = array_filter([
                        'cum_recibido' => $item['cum_recibido'] ?? null,
                        'codigo_sanitario' => $item['codigo_sanitario'] ?? null,
                        'cantidad_recibida' => $item['cantidad_recibida'] ?? null,
                        'numero_lote' => $item['numero_lote'] ?? null,
                        'fecha_vencimiento' => $item['fecha_vencimiento'] ?? null,
                    ], fn ($v) => $v !== null && $v !== '');

                    if (!empty($pedidoUpdates)) {
                        InvPedidoDetalle::where('id', $item['pedido_detalle_id'])->update($pedidoUpdates);
                    }
                }
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
            // Validaciones farmacéuticas antes de confirmar
            $detalles = InvRecepcionDetalle::where('recepcion_id', $recepcion->id)->get();
            foreach ($detalles as $detalle) {
                if (strtolower($detalle->concepto_recepcion) === 'aprobado' || strtolower($detalle->concepto_recepcion) === 'aceptado') {
                    if (empty($detalle->numero_lote) || empty($detalle->fecha_vencimiento)) {
                        throw new \Exception("El producto '{$detalle->producto_nombre}' fue aprobado pero carece de Lote o Fecha de Vencimiento.");
                    }
                }
            }

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
