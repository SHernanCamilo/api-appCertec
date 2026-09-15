<?php

namespace App\Services\Inventory\Pharmacy;

use Illuminate\Support\Facades\DB;
use App\Services\Inventory\BranchAccessService;

/**
 * Servicio de reportes unificados de Farmacia (tablero tipo BI).
 *
 * Agrega en una sola respuesta los indicadores de los 3 procesos:
 * Pedidos, Órdenes de Compra y Recepciones Técnicas.
 *
 * Notas de implementación importantes:
 *  - Los estados en BD están inconsistentes (mayúsculas/minúsculas y filas con '').
 *    Todas las comparaciones y agrupaciones normalizan con LOWER() y mapean el
 *    vacío a 'pendiente' para no perder registros del histórico.
 *  - inv_ordenes_compra NO tiene columna 'total'; el valor se calcula siempre como
 *    SUM(cantidad_solicitada_compra * COALESCE(precio_unitario_compra, 0)).
 *  - inv_recepciones no tiene sucursal_id; se resuelve por la OC asociada.
 */
class InvReporteService
{
    public function __construct(private BranchAccessService $branchAccess)
    {
    }

    /**
     * Tablero unificado.
     *
     * @param array $filters fecha_desde, fecha_hasta, proveedor, estado_pedido,
     *                       estado_recepcion, sucursal_id, user_id
     */
    public function getDashboard(array $filters = []): array
    {
        $desde = $filters['fecha_desde'] ?? now()->subDays(45)->toDateString();
        $hasta = $filters['fecha_hasta'] ?? now()->toDateString();

        // Sucursales visibles para el usuario (null = todas).
        $sucursales = null;
        if (!empty($filters['user_id'])) {
            $sucursales = $this->branchAccess->getSucursalIdsPermitidas((int) $filters['user_id']);
        }
        // Filtro puntual de una sucursal (debe estar dentro de las permitidas).
        $sucursalId = !empty($filters['sucursal_id']) ? (int) $filters['sucursal_id'] : null;

        return [
            'success' => true,
            'data' => [
                'rango'                   => ['desde' => $desde, 'hasta' => $hasta],
                'kpis'                    => $this->kpis($desde, $hasta, $sucursales, $sucursalId, $filters),
                'evolucion'               => $this->evolucion($desde, $hasta, $sucursales, $sucursalId),
                'pedidos_por_estado'      => $this->pedidosPorEstado($desde, $hasta, $sucursales, $sucursalId),
                'productos_por_categoria' => $this->productosPorCategoria($desde, $hasta, $sucursales, $sucursalId),
                'ultimos_pedidos'         => $this->ultimosPedidos($desde, $hasta, $sucursales, $sucursalId, $filters),
                'ultimas_ordenes'         => $this->ultimasOrdenes($desde, $hasta, $sucursales, $sucursalId, $filters),
                'ultimas_recepciones'     => $this->ultimasRecepciones($desde, $hasta, $sucursales, $sucursalId),
                'resumen_por_proveedor'   => $this->resumenPorProveedor($desde, $hasta, $sucursales, $sucursalId),
                'productos_mas_solicitados' => $this->productosMasSolicitados($desde, $hasta, $sucursales, $sucursalId),
            ],
        ];
    }

    /** Aplica el filtro de sucursales permitidas + sucursal puntual sobre una columna. */
    private function aplicarSucursal($query, string $columna, ?array $sucursales, ?int $sucursalId)
    {
        if ($sucursales !== null) {
            $query->where(function ($q) use ($columna, $sucursales) {
                $q->whereIn($columna, $sucursales)->orWhereNull($columna);
            });
        }
        if ($sucursalId) {
            $query->where($columna, $sucursalId);
        }
        return $query;
    }

    /** Tarjetas superiores del tablero. */
    private function kpis(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId, array $filters): array
    {
        // ── Pedidos ────────────────────────────────────────────────
        $qPedidos = DB::table('inv_pedidos')->whereBetween('fecha_pedido', [$desde, $hasta]);
        $this->aplicarSucursal($qPedidos, 'sucursal_id', $sucursales, $sucursalId);
        if (!empty($filters['proveedor'])) {
            $qPedidos->where('proveedor', 'LIKE', '%' . $filters['proveedor'] . '%');
        }
        $totalPedidos = (clone $qPedidos)->count();

        // ── Órdenes de compra + valor ──────────────────────────────
        $qOc = DB::table('inv_ordenes_compra')->whereBetween('fecha_orden', [$desde, $hasta]);
        $this->aplicarSucursal($qOc, 'sucursal_id', $sucursales, $sucursalId);
        if (!empty($filters['proveedor'])) {
            $qOc->where('proveedor_nombre', 'LIKE', '%' . $filters['proveedor'] . '%');
        }
        $totalOrdenes = (clone $qOc)->count();

        // Valor de compras: se calcula desde los detalles (no existe columna total).
        $valorCompras = (float) DB::table('inv_orden_compra_detalles as cd')
            ->join('inv_ordenes_compra as c', 'c.id', '=', 'cd.compra_id')
            ->whereBetween('c.fecha_orden', [$desde, $hasta])
            ->whereNotIn(DB::raw('LOWER(c.estado)'), ['cancelada', 'rechazada'])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->sum(DB::raw('cd.cantidad_solicitada_compra * COALESCE(cd.precio_unitario_compra, 0)'));

        // ── Recepciones ────────────────────────────────────────────
        $qRec = DB::table('inv_recepciones as r')
            ->leftJoin('inv_ordenes_compra as c', 'c.id', '=', 'r.compra_id')
            ->whereBetween(DB::raw('DATE(r.fecha_recepcion)'), [$desde, $hasta]);
        if ($sucursales !== null) {
            $qRec->where(fn ($w) => $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id'));
        }
        if ($sucursalId) {
            $qRec->where('c.sucursal_id', $sucursalId);
        }
        $totalRecepciones = (clone $qRec)->count();

        // Productos efectivamente recibidos (suma de cantidades).
        $productosRecibidos = (float) DB::table('inv_recepcion_detalles as rd')
            ->join('inv_recepciones as r', 'r.id', '=', 'rd.recepcion_id')
            ->leftJoin('inv_ordenes_compra as c', 'c.id', '=', 'r.compra_id')
            ->whereBetween(DB::raw('DATE(r.fecha_recepcion)'), [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->sum('rd.cantidad_recibida');

        // Pendientes por recibir: OC confirmadas/en sitio que aún no se recepcionaron del todo.
        $pendientesRecibir = (int) DB::table('inv_ordenes_compra as c')
            ->whereIn(DB::raw('LOWER(c.estado)'), ['confirmado', 'en_sitio', 'en_transito'])
            ->whereBetween('c.fecha_orden', [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->count();

        // Incidencias: ítems recepcionados con concepto rechazado o que no cumplen.
        $incidencias = (int) DB::table('inv_recepcion_detalles as rd')
            ->join('inv_recepciones as r', 'r.id', '=', 'rd.recepcion_id')
            ->leftJoin('inv_ordenes_compra as c', 'c.id', '=', 'r.compra_id')
            ->whereBetween(DB::raw('DATE(r.fecha_recepcion)'), [$desde, $hasta])
            ->where(function ($q) {
                $q->whereRaw('LOWER(rd.concepto_recepcion) = ?', ['rechazado'])
                  ->orWhere('rd.aspecto_cumple', 0)
                  ->orWhere('rd.embalaje_cumple', 0)
                  ->orWhere('rd.contenido_cumple', 0);
            })
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->count();

        return [
            'total_pedidos'        => $totalPedidos,
            'total_ordenes'        => $totalOrdenes,
            'total_recepciones'    => $totalRecepciones,
            'productos_recibidos'  => round($productosRecibidos, 2),
            'pendientes_recibir'   => $pendientesRecibir,
            'incidencias'          => $incidencias,
            'valor_total_compras'  => round($valorCompras, 2),
        ];
    }

    /** Serie temporal de los 3 procesos para el gráfico de líneas. */
    private function evolucion(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId): array
    {
        $pedidos = DB::table('inv_pedidos')
            ->selectRaw('DATE(fecha_pedido) as dia, COUNT(*) as total')
            ->whereBetween('fecha_pedido', [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('sucursal_id', $sucursales)->orWhereNull('sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->groupBy('dia')->pluck('total', 'dia');

        $ordenes = DB::table('inv_ordenes_compra')
            ->selectRaw('DATE(fecha_orden) as dia, COUNT(*) as total')
            ->whereBetween('fecha_orden', [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('sucursal_id', $sucursales)->orWhereNull('sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->groupBy('dia')->pluck('total', 'dia');

        $recepciones = DB::table('inv_recepciones as r')
            ->leftJoin('inv_ordenes_compra as c', 'c.id', '=', 'r.compra_id')
            ->selectRaw('DATE(r.fecha_recepcion) as dia, COUNT(*) as total')
            ->whereBetween(DB::raw('DATE(r.fecha_recepcion)'), [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->groupBy('dia')->pluck('total', 'dia');

        // Construir el eje de fechas continuo para que el gráfico no tenga huecos.
        $labels = [];
        $cursor = strtotime($desde);
        $fin    = strtotime($hasta);
        // Límite de seguridad para rangos muy amplios (máx. 180 puntos).
        $maxPuntos = 180;
        while ($cursor <= $fin && count($labels) < $maxPuntos) {
            $labels[] = date('Y-m-d', $cursor);
            $cursor = strtotime('+1 day', $cursor);
        }

        return [
            'labels'      => $labels,
            'pedidos'     => array_map(fn ($d) => (int) ($pedidos[$d] ?? 0), $labels),
            'ordenes'     => array_map(fn ($d) => (int) ($ordenes[$d] ?? 0), $labels),
            'recepciones' => array_map(fn ($d) => (int) ($recepciones[$d] ?? 0), $labels),
        ];
    }

    /** Distribución de pedidos por estado (dona). Normaliza vacíos a 'pendiente'. */
    private function pedidosPorEstado(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId): array
    {
        // Se agrupa por la EXPRESIÓN completa (no por el alias): MySQL agruparía
        // '' y 'pendiente' por separado y saldrían dos filas "Pendiente".
        $expr = "LOWER(COALESCE(NULLIF(TRIM(estado), ''), 'pendiente'))";

        $rows = DB::table('inv_pedidos')
            ->selectRaw("$expr as estado, COUNT(*) as total")
            ->whereBetween('fecha_pedido', [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('sucursal_id', $sucursales)->orWhereNull('sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->groupBy(DB::raw($expr))
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');

        return [
            'total'  => $total,
            'items'  => $rows->map(fn ($r) => [
                'estado'     => $r->estado,
                'label'      => $this->labelEstado($r->estado),
                'total'      => (int) $r->total,
                'porcentaje' => $total > 0 ? round(($r->total / $total) * 100, 1) : 0,
            ])->values()->all(),
        ];
    }

    /** Top de categorías/tipos de producto por cantidad solicitada. */
    private function productosPorCategoria(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId): array
    {
        // Agrupar por la expresión completa para que NULL y '' caigan en el mismo grupo.
        $expr = "COALESCE(NULLIF(TRIM(pd.producto_tipo), ''), 'Sin categoría')";

        $rows = DB::table('inv_pedido_detalles as pd')
            ->join('inv_pedidos as p', 'p.id', '=', 'pd.pedido_id')
            ->selectRaw("$expr as categoria, SUM(pd.cantidad_solicitada) as total")
            ->whereBetween('p.fecha_pedido', [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('p.sucursal_id', $sucursales)->orWhereNull('p.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('p.sucursal_id', $sucursalId))
            ->groupBy(DB::raw($expr))
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return $rows->map(fn ($r) => [
            'categoria' => $r->categoria,
            'total'     => (int) $r->total,
        ])->all();
    }

    /** Últimos pedidos con su valor calculado. */
    private function ultimosPedidos(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId, array $filters): array
    {
        return DB::table('inv_pedidos as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.solicitado_por')
            ->selectRaw("p.id, p.numero_pedido, p.proveedor, p.fecha_pedido,
                         LOWER(COALESCE(NULLIF(TRIM(p.estado), ''), 'pendiente')) as estado,
                         p.total_articulos, u.name as solicitado_por_nombre,
                         (SELECT COALESCE(SUM(d.cantidad_solicitada * COALESCE(d.precio_unitario,0)),0)
                            FROM inv_pedido_detalles d WHERE d.pedido_id = p.id) as valor")
            ->whereBetween('p.fecha_pedido', [$desde, $hasta])
            ->when(!empty($filters['proveedor']), fn ($q) => $q->where('p.proveedor', 'LIKE', '%' . $filters['proveedor'] . '%'))
            ->when(!empty($filters['estado_pedido']), fn ($q) => $q->whereRaw('LOWER(p.estado) = ?', [strtolower($filters['estado_pedido'])]))
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('p.sucursal_id', $sucursales)->orWhereNull('p.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('p.sucursal_id', $sucursalId))
            ->orderByDesc('p.id')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'              => $r->id,
                'numero_pedido'   => $r->numero_pedido,
                'proveedor'       => $r->proveedor,
                'fecha_pedido'    => $r->fecha_pedido,
                'estado'          => $r->estado,
                'estado_label'    => $this->labelEstado($r->estado),
                'total_articulos' => (int) $r->total_articulos,
                'solicitado_por_nombre' => $r->solicitado_por_nombre,
                'valor'           => round((float) $r->valor, 2),
            ])->all();
    }

    /** Últimas órdenes de compra con su valor calculado. */
    private function ultimasOrdenes(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId, array $filters): array
    {
        return DB::table('inv_ordenes_compra as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.creado_por')
            ->selectRaw("c.id, c.numero_orden_compra, c.oc_indigo, c.proveedor_nombre, c.fecha_orden,
                         LOWER(COALESCE(NULLIF(TRIM(c.estado), ''), 'pendiente')) as estado,
                         u.name as creado_por_nombre,
                         (SELECT COUNT(*) FROM inv_orden_compra_detalles d WHERE d.compra_id = c.id) as items,
                         (SELECT COALESCE(SUM(d.cantidad_solicitada_compra * COALESCE(d.precio_unitario_compra,0)),0)
                            FROM inv_orden_compra_detalles d WHERE d.compra_id = c.id) as valor")
            ->whereBetween('c.fecha_orden', [$desde, $hasta])
            ->when(!empty($filters['proveedor']), fn ($q) => $q->where('c.proveedor_nombre', 'LIKE', '%' . $filters['proveedor'] . '%'))
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->orderByDesc('c.id')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'                  => $r->id,
                'numero_orden_compra' => $r->numero_orden_compra,
                'oc_indigo'           => $r->oc_indigo,
                'proveedor_nombre'    => $r->proveedor_nombre,
                'fecha_orden'         => $r->fecha_orden,
                'estado'              => $r->estado,
                'estado_label'        => $this->labelEstado($r->estado),
                'creado_por_nombre'   => $r->creado_por_nombre,
                'items'               => (int) $r->items,
                'valor'               => round((float) $r->valor, 2),
            ])->all();
    }

    /** Últimas recepciones técnicas. */
    private function ultimasRecepciones(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId): array
    {
        return DB::table('inv_recepciones as r')
            ->leftJoin('inv_ordenes_compra as c', 'c.id', '=', 'r.compra_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.recibido_por')
            ->selectRaw("r.id, r.numero_recepcion, r.numero_orden_compra, r.fecha_recepcion,
                         LOWER(COALESCE(NULLIF(TRIM(r.estado), ''), 'recepcionado')) as estado,
                         u.name as recibido_por_nombre, r.total_items,
                         (SELECT COUNT(*) FROM inv_recepcion_detalles d
                           WHERE d.recepcion_id = r.id AND d.cantidad_recibida > 0) as items_recibidos")
            ->whereBetween(DB::raw('DATE(r.fecha_recepcion)'), [$desde, $hasta])
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('c.sucursal_id', $sucursalId))
            ->orderByDesc('r.id')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'id'                  => $r->id,
                'numero_recepcion'    => $r->numero_recepcion,
                'numero_orden_compra' => $r->numero_orden_compra,
                'fecha_recepcion'     => $r->fecha_recepcion,
                'estado'              => $r->estado,
                'estado_label'        => $this->labelEstado($r->estado),
                'recibido_por_nombre' => $r->recibido_por_nombre,
                'total_items'         => (int) $r->total_items,
                'items_recibidos'     => (int) $r->items_recibidos,
            ])->all();
    }

    /** Consolidado por proveedor cruzando los 3 procesos. */
    private function resumenPorProveedor(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId): array
    {
        // El proveedor confiable está en la OC. Se resuelve con dos consultas simples
        // (evita subconsultas correlacionadas y el fan-out de unir detalles + recepciones
        // en un mismo GROUP BY, que multiplicaría los importes).
        $expr = "COALESCE(NULLIF(TRIM(c.proveedor_nombre), ''), 'Sin proveedor')";

        $aplicarFiltros = function ($q) use ($desde, $hasta, $sucursales, $sucursalId) {
            $q->whereBetween('c.fecha_orden', [$desde, $hasta]);
            if ($sucursales !== null) {
                $q->where(fn ($w) => $w->whereIn('c.sucursal_id', $sucursales)->orWhereNull('c.sucursal_id'));
            }
            if ($sucursalId) {
                $q->where('c.sucursal_id', $sucursalId);
            }
            return $q;
        };

        // 1) Valor y unidades desde los detalles (una fila por detalle).
        $base = DB::table('inv_ordenes_compra as c')
            ->join('inv_orden_compra_detalles as d', 'd.compra_id', '=', 'c.id')
            ->selectRaw("$expr as proveedor,
                         COUNT(DISTINCT c.id) as ordenes,
                         COALESCE(SUM(d.cantidad_solicitada_compra * COALESCE(d.precio_unitario_compra,0)),0) as valor,
                         COALESCE(SUM(d.cantidad_solicitada_compra),0) as productos")
            ->groupBy(DB::raw($expr))
            ->orderByDesc('valor')
            ->limit(10);
        $aplicarFiltros($base);
        $rows = $base->get();

        // 2) Recepciones por proveedor (conteo independiente, sin inflar importes).
        $recep = DB::table('inv_ordenes_compra as c')
            ->join('inv_recepciones as r', 'r.compra_id', '=', 'c.id')
            ->selectRaw("$expr as proveedor, COUNT(DISTINCT r.id) as recepciones")
            ->groupBy(DB::raw($expr));
        $aplicarFiltros($recep);
        $recepciones = $recep->pluck('recepciones', 'proveedor');

        return $rows->map(fn ($r) => [
            'proveedor'   => $r->proveedor,
            'ordenes'     => (int) $r->ordenes,
            'recepciones' => (int) ($recepciones[$r->proveedor] ?? 0),
            'productos'   => (int) $r->productos,
            'valor'       => round((float) $r->valor, 2),
        ])->all();
    }

    /** Ranking de productos más solicitados en pedidos. */
    private function productosMasSolicitados(string $desde, string $hasta, ?array $sucursales, ?int $sucursalId): array
    {
        $rows = DB::table('inv_pedido_detalles as pd')
            ->join('inv_pedidos as p', 'p.id', '=', 'pd.pedido_id')
            ->selectRaw("pd.codigo_producto, MAX(pd.producto_nombre) as producto_nombre,
                         SUM(pd.cantidad_solicitada) as cantidad")
            ->whereBetween('p.fecha_pedido', [$desde, $hasta])
            ->whereRaw("TRIM(COALESCE(pd.codigo_producto,'')) <> ''")
            ->when($sucursales !== null, fn ($q) => $q->where(fn ($w) =>
                $w->whereIn('p.sucursal_id', $sucursales)->orWhereNull('p.sucursal_id')))
            ->when($sucursalId, fn ($q) => $q->where('p.sucursal_id', $sucursalId))
            ->groupBy('pd.codigo_producto')
            ->orderByDesc('cantidad')
            ->limit(8)
            ->get();

        $maximo = (float) ($rows->max('cantidad') ?: 1);

        return $rows->map(fn ($r) => [
            'codigo_producto' => $r->codigo_producto,
            'producto_nombre' => $r->producto_nombre,
            'cantidad'        => (int) $r->cantidad,
            // Porcentaje relativo al más solicitado (para la barra del ranking).
            'porcentaje'      => round(((float) $r->cantidad / $maximo) * 100, 1),
        ])->all();
    }

    /** Etiqueta legible de un estado (normalizado a minúsculas). */
    private function labelEstado(string $estado): string
    {
        return match (strtolower(trim($estado))) {
            'borrador'      => 'Borrador',
            'pendiente'     => 'Pendiente',
            'solicitado'    => 'Solicitado',
            'aprobado'      => 'Aprobado',
            'confirmado'    => 'Confirmado',
            'en_proceso'    => 'En Proceso',
            'en_transito'   => 'En Tránsito',
            'en_sitio'      => 'En Sitio',
            'parcial'       => 'Parcial',
            'recibido',
            'recibida'      => 'Recibido',
            'recepcionado'  => 'Recepcionado',
            'rechazado',
            'rechazada'     => 'Rechazado',
            'cancelado',
            'cancelada'     => 'Cancelado',
            default         => ucfirst($estado ?: 'Pendiente'),
        };
    }
}
