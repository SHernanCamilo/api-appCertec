-- Vista recepción técnica (equivalente digipharma.vista_compras_recepcion)
-- Ejecutar en medadminvps_Jade-plataform si no se usa artisan migrate

DROP VIEW IF EXISTS vista_inv_compras_recepcion;

CREATE VIEW vista_inv_compras_recepcion AS
SELECT
    cd.id AS detalle_id,
    cd.compra_id,
    cd.pedido_detalle_id,
    c.numero_orden_compra,
    c.oc_indigo,
    c.fecha_orden,
    c.estado AS estado_compra,
    c.proveedor_nombre,
    pd.pedido_id,
    p.numero_pedido,
    p.estado AS estado_pedido,
    COALESCE(pd.codigo_producto, cd.codigo_producto_indigo) AS codigo_producto,
    COALESCE(pd.producto_nombre, cd.producto_nombre) AS producto_nombre,
    pd.producto_tipo,
    pd.producto_marca AS marca,
    cd.cantidad_solicitada_compra,
    pd.cantidad_solicitada AS cantidad_solicitada_pedido,
    pd.cantidad_recibida,
    COALESCE(cd.proveedor, c.proveedor_nombre) AS proveedor,
    cd.clasificacion_vie,
    cd.clasificacion_venta,
    cd.precio_unitario_compra,
    cd.fecha_entrega_estimada,
    cd.observaciones AS observaciones_compra,
    cd.estado AS estado_detalle_compra,
    pd.numero_lote,
    pd.fecha_vencimiento,
    pd.cum_recibido,
    pd.codigo_sanitario,
    pd.aspecto_cumple,
    pd.embalaje_cumple,
    pd.contenido_cumple,
    pd.cadena_frio_temperatura,
    pd.concepto_recepcion,
    pd.observaciones AS observaciones_pedido,
    pd.estado AS estado_detalle_pedido
FROM inv_orden_compra_detalles cd
INNER JOIN inv_ordenes_compra c ON c.id = cd.compra_id
LEFT JOIN inv_pedido_detalles pd ON pd.id = cd.pedido_detalle_id
LEFT JOIN inv_pedidos p ON p.id = pd.pedido_id;
