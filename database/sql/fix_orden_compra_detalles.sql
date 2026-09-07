-- ============================================================================
-- FIX: Completar datos faltantes en inv_orden_compra_detalles
-- Base: medadminvps_Jade-plataform (VPS 72.167.224.130)
-- Fecha: 2026-08-19
-- 
-- Problema: La tabla compras_detalle de Digipharma NO tiene las columnas 
-- codigo_producto_indigo ni producto_nombre. Esos datos vienen del JOIN 
-- con pedidos_detalle (ahora inv_pedido_detalles en la VPS).
-- Además, la tabla compras NO tiene columna proveedor - está en compras_detalle.
-- ============================================================================

-- 1. Poblar codigo_producto_indigo y producto_nombre desde inv_pedido_detalles
UPDATE inv_orden_compra_detalles ocd
INNER JOIN inv_pedido_detalles pd ON pd.id = ocd.pedido_detalle_id
SET 
    ocd.codigo_producto_indigo = pd.codigo_producto,
    ocd.producto_nombre = pd.producto_nombre
WHERE (ocd.codigo_producto_indigo IS NULL OR ocd.codigo_producto_indigo = '')
  AND ocd.pedido_detalle_id IS NOT NULL;

-- 2. Poblar proveedor_nombre en inv_ordenes_compra desde el primer detalle de cada OC
UPDATE inv_ordenes_compra oc
INNER JOIN (
    SELECT compra_id, MIN(id) as primer_detalle_id
    FROM inv_orden_compra_detalles
    WHERE proveedor IS NOT NULL AND proveedor != ''
    GROUP BY compra_id
) first_det ON first_det.compra_id = oc.id
INNER JOIN inv_orden_compra_detalles ocd ON ocd.id = first_det.primer_detalle_id
SET oc.proveedor_nombre = ocd.proveedor
WHERE oc.proveedor_nombre IS NULL OR oc.proveedor_nombre = '';

-- 3. Verificación de proveedores
SELECT 
    COUNT(*) as total_ordenes,
    SUM(CASE WHEN proveedor_nombre IS NOT NULL AND proveedor_nombre != '' THEN 1 ELSE 0 END) as con_proveedor,
    SUM(CASE WHEN proveedor_nombre IS NULL OR proveedor_nombre = '' THEN 1 ELSE 0 END) as sin_proveedor
FROM inv_ordenes_compra;

-- 4. Verificación de detalles completos
SELECT 
    COUNT(*) as total_detalles,
    SUM(CASE WHEN codigo_producto_indigo IS NOT NULL AND codigo_producto_indigo != '' THEN 1 ELSE 0 END) as con_codigo,
    SUM(CASE WHEN producto_nombre IS NOT NULL AND producto_nombre != '' THEN 1 ELSE 0 END) as con_nombre,
    SUM(CASE WHEN cantidad_solicitada_compra > 0 THEN 1 ELSE 0 END) as con_cantidad,
    SUM(CASE WHEN precio_unitario_compra > 0 THEN 1 ELSE 0 END) as con_precio,
    SUM(CASE WHEN pedido_detalle_id IS NULL THEN 1 ELSE 0 END) as sin_pedido_detalle
FROM inv_orden_compra_detalles;

-- 5. Muestra de OCs con proveedor para verificación
SELECT id, numero_orden_compra, proveedor_nombre, estado, fecha_orden
FROM inv_ordenes_compra
WHERE id >= 170
ORDER BY id DESC
LIMIT 15;
