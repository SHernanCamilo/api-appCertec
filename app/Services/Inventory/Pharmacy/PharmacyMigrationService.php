<?php

namespace App\Services\Inventory\Pharmacy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de migración de datos desde Digipharma (legacy) hacia la VPS.
 *
 * Este servicio se puede ejecutar manualmente vía artisan command para
 * transferir los datos existentes de la 192.168.12.20 (digipharma) hacia
 * la base de datos de la VPS (medadminvps_Jade-plataform).
 *
 * Requiere configuración de conexión 'digipharma' en config/database.php
 */
class PharmacyMigrationService
{
    private string $sourceConnection = 'digipharma';
    private string $targetConnection = 'mysql'; // VPS principal

    /**
     * Ejecutar migración completa de datos.
     */
    public function migrateAll(int $userId = 1): array
    {
        $stats = [
            'pedidos' => 0,
            'pedidos_detalle' => 0,
            'compras' => 0,
            'compras_detalle' => 0,
            'compras_pedidos' => 0,
            'indigo_items' => 0,
            'indigo_eventos' => 0,
            'recepciones' => 0,
            'recepciones_detalle' => 0,
            'muestreo_niveles' => 0,
            'muestreo_exclusiones' => 0,
            'errores' => [],
        ];

        Log::info('[PHARMACY-MIGRATION] Iniciando migración desde Digipharma');

        try {
            // 1. Pedidos
            $stats['pedidos'] = $this->migratePedidos();
            $stats['pedidos_detalle'] = $this->migratePedidosDetalle();

            // 2. Órdenes de Compra
            $stats['compras'] = $this->migrateCompras();
            $stats['compras_detalle'] = $this->migrateComprasDetalle();
            $stats['compras_pedidos'] = $this->migrateComprasPedidos();

            // 3. Indigo
            $stats['indigo_items'] = $this->migrateIndigoItems();
            $stats['indigo_eventos'] = $this->migrateIndigoEventos();

            // 4. Recepciones
            $stats['recepciones'] = $this->migrateRecepciones();
            $stats['recepciones_detalle'] = $this->migrateRecepcionesDetalle();

            // 5. Muestreo
            $stats['muestreo_niveles'] = $this->migrateMuestreoNiveles();
            $stats['muestreo_exclusiones'] = $this->migrateMuestreoExclusiones();

        } catch (\Exception $e) {
            $stats['errores'][] = $e->getMessage();
            Log::error('[PHARMACY-MIGRATION] Error general: ' . $e->getMessage());
        }

        Log::info('[PHARMACY-MIGRATION] Migración completada', $stats);
        return $stats;
    }

    private function migratePedidos(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('pedidos')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::connection($this->targetConnection)
                ->table('inv_pedidos')
                ->updateOrInsert(
                    ['numero_pedido' => $row->numero_pedido],
                    [
                        'proveedor' => $row->proveedor,
                        'fecha_pedido' => $row->fecha_pedido,
                        'fecha_esperada' => $row->fecha_esperada,
                        'fecha_recibido' => $row->fecha_recibido,
                        'estado' => $row->estado,
                        'total_articulos' => $row->total_articulos,
                        'observaciones' => $row->observaciones,
                        'solicitado_por' => 1, // Mapear a admin VPS
                        'recibido_por' => $row->recibido_por ? 1 : null,
                        'aprobado_por' => $row->aprobado_por ? 1 : null,
                        'cancelado_por' => $row->cancelado_por ? 1 : null,
                        'created_at' => $row->creado_en,
                        'updated_at' => $row->actualizado_en,
                    ]
                );
            $count++;
        }
        return $count;
    }

    private function migratePedidosDetalle(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('pedidos_detalle')
            ->get();

        $count = 0;
        foreach ($rows->chunk(100) as $chunk) {
            foreach ($chunk as $row) {
                // Buscar pedido_id en destino
                $pedido = DB::connection($this->targetConnection)
                    ->table('inv_pedidos')
                    ->where('id', $row->pedido_id)
                    ->first();

                if (!$pedido) continue;

                DB::connection($this->targetConnection)
                    ->table('inv_pedido_detalles')
                    ->updateOrInsert(
                        ['id' => $row->id],
                        [
                            'pedido_id' => $row->pedido_id,
                            'codigo_producto' => $row->codigo_producto,
                            'producto_nombre' => $row->producto_nombre,
                            'producto_tipo' => $row->producto_tipo,
                            'producto_marca' => $row->producto_marca,
                            'producto_promedio' => $row->producto_promedio,
                            'producto_rotacion' => $row->producto_rotacion,
                            'codigo_sanitario' => $row->codigo_sanitario,
                            'cum_recibido' => $row->cum_recibido,
                            'cantidad_solicitada' => $row->cantidad_solicitada,
                            'cantidad_recibida' => $row->cantidad_recibida,
                            'numero_lote' => $row->numero_lote,
                            'fecha_vencimiento' => $row->fecha_vencimiento,
                            'precio_unitario' => $row->precio_unitario,
                            'estado' => $row->estado,
                            'aspecto_cumple' => $row->aspecto_cumple,
                            'embalaje_cumple' => $row->embalaje_cumple,
                            'cadena_frio_temperatura' => $row->cadena_frio_temperatura,
                            'contenido_cumple' => $row->contenido_cumple,
                            'concepto_recepcion' => $row->concepto_recepcion,
                            'recibido_por' => $row->recibido_por ? 1 : null,
                            'observaciones' => $row->observaciones,
                            'created_at' => $row->creado_en,
                            'updated_at' => $row->actualizado_en,
                        ]
                    );
                $count++;
            }
        }
        return $count;
    }

    private function migrateCompras(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('compras')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::connection($this->targetConnection)
                ->table('inv_ordenes_compra')
                ->updateOrInsert(
                    ['id' => $row->id],
                    [
                        'numero_orden_compra' => $row->numero_orden_compra,
                        'fecha_orden' => $row->fecha_orden,
                        'observaciones' => $row->observaciones,
                        'estado' => $row->estado,
                        'sincronizado_indigo' => $row->sincronizado_indigo,
                        'creado_por' => 1,
                        'oc_indigo' => $row->oc_indigo,
                        'created_at' => $row->creado_en,
                        'updated_at' => $row->actualizado_en,
                    ]
                );
            $count++;
        }
        return $count;
    }

    private function migrateComprasDetalle(): int
    {
        // JOIN con pedidos_detalle para obtener codigo_producto y producto_nombre
        // ya que compras_detalle NO tiene esas columnas en Digipharma
        $rows = DB::connection($this->sourceConnection)
            ->table('compras_detalle as cd')
            ->leftJoin('pedidos_detalle as pd', 'pd.id', '=', 'cd.pedido_detalle_id')
            ->select([
                'cd.id',
                'cd.compra_id',
                'cd.pedido_detalle_id',
                'cd.clasificacion_venta',
                'cd.proveedor',
                'cd.cantidad_solicitada_compra',
                'cd.fecha_entrega_estimada',
                'cd.clasificacion_vie',
                'cd.precio_unitario_compra',
                'cd.observaciones',
                'cd.estado',
                'cd.creado_en',
                'cd.actualizado_en',
                // Traer datos del producto desde pedidos_detalle
                'pd.codigo_producto',
                'pd.producto_nombre',
            ])
            ->get();

        $count = 0;
        foreach ($rows->chunk(100) as $chunk) {
            foreach ($chunk as $row) {
                DB::connection($this->targetConnection)
                    ->table('inv_orden_compra_detalles')
                    ->updateOrInsert(
                        ['id' => $row->id],
                        [
                            'compra_id' => $row->compra_id,
                            'pedido_detalle_id' => $row->pedido_detalle_id,
                            'codigo_producto_indigo' => $row->codigo_producto ?? null,
                            'producto_nombre' => $row->producto_nombre ?? null,
                            'clasificacion_venta' => $row->clasificacion_venta ?? null,
                            'proveedor' => $row->proveedor ?? '',
                            'cantidad_solicitada_compra' => $row->cantidad_solicitada_compra,
                            'fecha_entrega_estimada' => $row->fecha_entrega_estimada ?? null,
                            'clasificacion_vie' => $row->clasificacion_vie ?? null,
                            'precio_unitario_compra' => $row->precio_unitario_compra ?? null,
                            'observaciones' => $row->observaciones ?? null,
                            'estado' => $row->estado ?? 'pendiente',
                            'created_at' => $row->creado_en ?? now(),
                            'updated_at' => $row->actualizado_en ?? now(),
                        ]
                    );
                $count++;
            }
        }
        return $count;
    }

    private function migrateComprasPedidos(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('compras_pedidos')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::connection($this->targetConnection)
                ->table('inv_compras_pedidos')
                ->insertOrIgnore([
                    'compra_id' => $row->compra_id,
                    'pedido_id' => $row->pedido_id,
                ]);
            $count++;
        }
        return $count;
    }

    private function migrateIndigoItems(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('indigo_ordenes_items')
            ->get();

        $count = 0;
        foreach ($rows->chunk(200) as $chunk) {
            foreach ($chunk as $row) {
                DB::connection($this->targetConnection)
                    ->table('inv_indigo_items')
                    ->updateOrInsert(
                        [
                            'orden_compra' => $row->orden_compra,
                            'codigo_producto' => $row->codigo_producto,
                            'numero_pedido' => $row->numero_pedido,
                        ],
                        [
                            'pedido_id' => $row->pedido_id,
                            'pedido_detalle_id' => $row->pedido_detalle_id,
                            'proveedor' => $row->proveedor,
                            'cantidad_origen' => $row->cantidad_origen,
                            'cantidad_aplicada' => $row->cantidad_aplicada,
                            'fecha_indigo' => $row->fecha_indigo,
                            'estado_orden' => $row->estado_orden,
                            'descripcion_orden' => $row->descripcion_orden,
                            'created_at' => $row->creado_en,
                        ]
                    );
                $count++;
            }
        }
        return $count;
    }

    private function migrateIndigoEventos(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('indigo_ordenes_eventos')
            ->get();

        $count = 0;
        $inserts = [];
        foreach ($rows as $row) {
            $inserts[] = [
                'numero_pedido' => $row->numero_pedido,
                'orden_compra' => $row->orden_compra,
                'codigo_producto' => $row->codigo_producto,
                'nivel' => $row->nivel,
                'mensaje' => $row->mensaje,
                'created_at' => $row->creado_en,
            ];
            $count++;
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            DB::connection($this->targetConnection)
                ->table('inv_indigo_eventos')
                ->insert($chunk);
        }
        return $count;
    }

    private function migrateRecepciones(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('recepciones_historico')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::connection($this->targetConnection)
                ->table('inv_recepciones')
                ->updateOrInsert(
                    ['id' => $row->id],
                    [
                        'numero_recepcion' => $row->numero_recepcion,
                        'compra_id' => $row->compra_id,
                        'numero_orden_compra' => $row->numero_orden_compra,
                        'oc_indigo' => $row->oc_indigo,
                        'fecha_recepcion' => $row->fecha_recepcion,
                        'recibido_por' => 1,
                        'total_items' => $row->total_items,
                        'observaciones' => $row->observaciones,
                        'estado' => $row->estado,
                        'created_at' => $row->creado_en,
                        'updated_at' => $row->actualizado_en,
                    ]
                );
            $count++;
        }
        return $count;
    }

    private function migrateRecepcionesDetalle(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('recepciones_historico_detalle')
            ->get();

        $count = 0;
        foreach ($rows->chunk(100) as $chunk) {
            foreach ($chunk as $row) {
                DB::connection($this->targetConnection)
                    ->table('inv_recepcion_detalles')
                    ->updateOrInsert(
                        ['id' => $row->id],
                        [
                            'recepcion_id' => $row->recepcion_id,
                            'pedido_detalle_id' => $row->pedido_detalle_id,
                            'codigo_producto' => $row->codigo_producto,
                            'producto_nombre' => $row->producto_nombre,
                            'cantidad_solicitada' => $row->cantidad_solicitada,
                            'cantidad_recibida' => $row->cantidad_recibida,
                            'numero_lote' => $row->numero_lote,
                            'fecha_vencimiento' => $row->fecha_vencimiento == '0000-00-00' ? null : $row->fecha_vencimiento,
                            'codigo_sanitario' => $row->codigo_sanitario,
                            'aspecto_cumple' => $row->aspecto_cumple,
                            'embalaje_cumple' => $row->embalaje_cumple,
                            'contenido_cumple' => $row->contenido_cumple,
                            'cadena_frio_temperatura' => $row->cadena_frio_temperatura,
                            'concepto_recepcion' => $row->concepto_recepcion,
                            'es_medicamento_vital' => $row->es_medicamento_vital,
                            'mvd_ium' => $row->mvd_ium,
                            'mvd_solicitante' => $row->mvd_solicitante,
                            'mvd_principio_activo' => $row->mvd_principio_activo,
                            'mvd_forma_farmaceutica' => $row->mvd_forma_farmaceutica,
                            'mvd_presentacion_comercial' => $row->mvd_presentacion_comercial,
                            'mvd_fecha_autorizacion' => $row->mvd_fecha_autorizacion,
                            'observaciones_recepcion' => $row->observaciones_recepcion,
                            'created_at' => $row->creado_en,
                        ]
                    );
                $count++;
            }
        }
        return $count;
    }

    private function migrateMuestreoNiveles(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('formula_magistral_muestra')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::connection($this->targetConnection)
                ->table('inv_muestreo_niveles')
                ->updateOrInsert(
                    ['id' => $row->id],
                    [
                        'nivel_inspeccion' => $row->nivel_inspeccion,
                        'lote_min' => $row->lote_min,
                        'lote_max' => $row->lote_max,
                        'letra_codigo' => $row->letra_codigo,
                        'tamano_muestra' => $row->tamano_muestra,
                        'activo' => $row->activo,
                        'created_at' => $row->creado_en,
                    ]
                );
            $count++;
        }
        return $count;
    }

    private function migrateMuestreoExclusiones(): int
    {
        $rows = DB::connection($this->sourceConnection)
            ->table('formula_magistral_muestra_exclusion')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            DB::connection($this->targetConnection)
                ->table('inv_muestreo_exclusiones')
                ->updateOrInsert(
                    ['id' => $row->id],
                    [
                        'codigo_producto' => $row->codigo_producto,
                        'nombre_producto' => $row->medicamento,
                        'motivo' => $row->clasificacion,
                        'activo' => $row->activo,
                        'created_at' => $row->creado_en,
                    ]
                );
            $count++;
        }
        return $count;
    }
}
