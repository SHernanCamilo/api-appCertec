-- =================================================================
-- SCRIPT DE RETROACTIVACIÓN: muestra_poblacion + muestra_exclusion
-- Objetivo: Corregir 507 registros NULL en inv_recepcion_detalles
--           de la VPS de producción (medadminvps_Jade-plataform).
--
-- IMPORTANTE:
--   • ESTE SCRIPT NO SE EJECUTA AUTOMÁTICAMENTE.
--   • PRIMERO ejecuta sólo el bloque 1 (SELECT / vista previa) para
--     validar los resultados en un cliente MySQL como DBeaver o
--     phpMyAdmin antes de cualquier UPDATE.
--   • Confirma los valores con el cliente antes de continuar.
-- =================================================================


-- =================================================================
-- BLOQUE 1 / 3: VISTA PREVIA (¡¡EJECUTAR PRIMERO!!)
-- =================================================================
-- Muestra los valores ANTES vs DESPUÉS (calculados on-the-fly)
-- para las 507 filas que tienen NULL/0 en muestra_poblacion.
--
SELECT
    rd.id                                         AS detalle_id,
    rd.codigo_producto                            AS codigo,
    LEFT(rd.producto_nombre, 60)                  AS producto,
    rd.cantidad_recibida                          AS cant_recibida,
    rd.muestra_poblacion                          AS actual_NULL_0,
    -- Nueva muestra_calculada (ISO 2859-1 Nivel II):
    CASE
      WHEN EXISTS (SELECT 1 FROM inv_muestreo_exclusiones me
                   WHERE me.activo = 1
                     AND (me.codigo_producto = rd.codigo_producto
                          OR me.nombre_producto = rd.producto_nombre))
        THEN ROUND(rd.cantidad_recibida, 0)          -- 100% inspección si está excluido
      WHEN rd.cantidad_recibida BETWEEN       2 AND        8 THEN    2
      WHEN rd.cantidad_recibida BETWEEN       9 AND       15 THEN    3
      WHEN rd.cantidad_recibida BETWEEN      16 AND       25 THEN    5
      WHEN rd.cantidad_recibida BETWEEN      26 AND       50 THEN    8
      WHEN rd.cantidad_recibida BETWEEN      51 AND       90 THEN   13
      WHEN rd.cantidad_recibida BETWEEN      91 AND      150 THEN   20
      WHEN rd.cantidad_recibida BETWEEN     151 AND      280 THEN   32
      WHEN rd.cantidad_recibida BETWEEN     281 AND      500 THEN   50
      WHEN rd.cantidad_recibida BETWEEN     501 AND     1200 THEN   80
      WHEN rd.cantidad_recibida BETWEEN    1201 AND     3200 THEN  125
      WHEN rd.cantidad_recibida BETWEEN    3201 AND    10000 THEN  200
      WHEN rd.cantidad_recibida BETWEEN   10001 AND    35000 THEN  315
      WHEN rd.cantidad_recibida BETWEEN   35001 AND   150000 THEN  500
      WHEN rd.cantidad_recibida BETWEEN  150001 AND   500000 THEN  800
      WHEN rd.cantidad_recibida >        500000                 THEN 1250
      ELSE COALESCE(ROUND(rd.cantidad_recibida * 0.10, 0), 0)     -- fallback 10% (edge-case < 2)
    END                                          AS muestra_calculada,
    -- muestra_exclusion
    CASE WHEN EXISTS (SELECT 1 FROM inv_muestreo_exclusiones me
                      WHERE me.activo = 1
                        AND (me.codigo_producto = rd.codigo_producto
                             OR me.nombre_producto = rd.producto_nombre))
      THEN 1 ELSE 0 END                          AS exclusion_calculada
FROM  inv_recepcion_detalles rd
WHERE rd.muestra_poblacion IS NULL OR rd.muestra_poblacion = 0
ORDER BY rd.id
LIMIT 1000;


-- =================================================================
-- BLOQUE 2 / 3: AÑADIR COLUMNA muestra_exclusion (si no existe)
-- =================================================================
-- Este paso es OPCIONAL si ya se ejecutó la migración Laravel:
-- 2026_08_26_000001_add_muestra_exclusion_and_invima_toggles_to_inv_recepcion_detalles
--
-- Si aún no se ha ejecutado, des-comenta y ejecuta estas líneas:
--
-- ALTER TABLE inv_recepcion_detalles
--   ADD COLUMN muestra_exclusion BOOLEAN NOT NULL DEFAULT FALSE
--     COMMENT 'TRUE si el producto está excluido de muestreo (100% inspección)'
--     AFTER muestra_poblacion;
--
-- ALTER TABLE inv_recepcion_detalles
--   ADD COLUMN invima_observaciones VARCHAR(255) NULL
--     COMMENT 'Justificación override manual estado Invima'
--     AFTER estado_invima;


-- =================================================================
-- BLOQUE 3 / 3: ACTUALIZAR LAS 507 FILAS
-- =================================================================
-- ¡¡ DESCOMENTAR SÓLO DESPUÉS DE CONFIRMAR EL BLOQUE 1 !!
--
-- UPDATE inv_recepcion_detalles rd
-- SET
--     rd.muestra_poblacion = CASE
--         WHEN EXISTS (SELECT 1 FROM inv_muestreo_exclusiones me
--                      WHERE me.activo = 1
--                        AND (me.codigo_producto = rd.codigo_producto
--                             OR me.nombre_producto = rd.producto_nombre))
--           THEN ROUND(rd.cantidad_recibida, 0)
--         WHEN rd.cantidad_recibida BETWEEN       2 AND        8 THEN    2
--         WHEN rd.cantidad_recibida BETWEEN       9 AND       15 THEN    3
--         WHEN rd.cantidad_recibida BETWEEN      16 AND       25 THEN    5
--         WHEN rd.cantidad_recibida BETWEEN      26 AND       50 THEN    8
--         WHEN rd.cantidad_recibida BETWEEN      51 AND       90 THEN   13
--         WHEN rd.cantidad_recibida BETWEEN      91 AND      150 THEN   20
--         WHEN rd.cantidad_recibida BETWEEN     151 AND      280 THEN   32
--         WHEN rd.cantidad_recibida BETWEEN     281 AND      500 THEN   50
--         WHEN rd.cantidad_recibida BETWEEN     501 AND     1200 THEN   80
--         WHEN rd.cantidad_recibida BETWEEN    1201 AND     3200 THEN  125
--         WHEN rd.cantidad_recibida BETWEEN    3201 AND    10000 THEN  200
--         WHEN rd.cantidad_recibida BETWEEN   10001 AND    35000 THEN  315
--         WHEN rd.cantidad_recibida BETWEEN   35001 AND   150000 THEN  500
--         WHEN rd.cantidad_recibida BETWEEN  150001 AND   500000 THEN  800
--         WHEN rd.cantidad_recibida >        500000                 THEN 1250
--         ELSE COALESCE(ROUND(rd.cantidad_recibida * 0.10, 0), 0)
--       END,
--     rd.muestra_exclusion = CASE WHEN EXISTS (
--         SELECT 1 FROM inv_muestreo_exclusiones me
--         WHERE me.activo = 1
--           AND (me.codigo_producto = rd.codigo_producto
--                OR me.nombre_producto = rd.producto_nombre)
--       ) THEN 1 ELSE 0 END,
--     rd.updated_at = NOW()
-- WHERE rd.muestra_poblacion IS NULL OR rd.muestra_poblacion = 0;
--
-- =================================================================
-- VERIFICACIÓN FINAL (ejecutar después del UPDATE):
--   SELECT COUNT(*) FROM inv_recepcion_detalles WHERE muestra_poblacion IS NULL OR muestra_poblacion = 0;
--   -- Resultado esperado: 0 filas
-- =================================================================
