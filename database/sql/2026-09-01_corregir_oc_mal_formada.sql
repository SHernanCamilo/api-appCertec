-- ============================================================================
-- Corrección de OC mal formada por el error del generador (FLA-IND-0000133827)
-- Base: medadminvps_Jade-plataform (VPS)
-- Fecha: 2026-09-01
--
-- Contexto: antes del fix, cuando el generador de secuencias fallaba (usuario
-- admin sin empresa en seg_empresa_user), MonitoringService creaba la OC con
-- nombre de relleno "FLA-IND-{numeroIndigo}". Ese caso ya no ocurre, pero quedó
-- 1 fila con ese formato. Este script la corrige.
--
-- IMPORTANTE: ejecutar los SELECT primero para revisar. NO ejecutar los UPDATE
-- hasta validar. Ninguna sentencia borra datos.
-- ============================================================================

-- 1) REVISIÓN: ver la OC mal formada y su detalle
SELECT id, numero_orden_compra, oc_indigo, estado, sucursal_id, sincronizado_indigo, created_at
FROM inv_ordenes_compra
WHERE numero_orden_compra LIKE '%IND-%';

-- 2) REVISIÓN: ver el siguiente consecutivo de Florencia (debe ser 179)
SELECT d.siguiente_numero, p.patron
FROM config_sec_detalles d
JOIN config_sec_secuencias s ON s.id = d.secuencia_id
JOIN seg_modulos m ON m.id = s.modulo_id
JOIN seg_modulos mp ON mp.id = s.proceso_id
LEFT JOIN config_sec_patrones p ON p.id = d.patron_id
WHERE m.codigo = 'INV' AND mp.codigo = 'INV-ORDEN_COMPRA' AND d.sucursal_id = 2;

-- ============================================================================
-- OPCIÓN A (recomendada si la OC es válida y solo tiene el nombre mal):
--   Renombrarla al consecutivo correcto y consumir el contador.
--   Ejecutar dentro de una transacción.
-- ============================================================================
-- START TRANSACTION;
--
-- -- Renombrar la OC al consecutivo correcto (ajustar 000179 si el contador cambió)
-- UPDATE inv_ordenes_compra
-- SET numero_orden_compra = 'FLA-2026-000179-OC'
-- WHERE numero_orden_compra = 'FLA-IND-0000133827';
--
-- -- Avanzar el contador de Florencia para que la próxima sea 180
-- UPDATE config_sec_detalles d
-- JOIN config_sec_secuencias s ON s.id = d.secuencia_id
-- JOIN seg_modulos m ON m.id = s.modulo_id
-- JOIN seg_modulos mp ON mp.id = s.proceso_id
-- SET d.siguiente_numero = 180
-- WHERE m.codigo = 'INV' AND mp.codigo = 'INV-ORDEN_COMPRA' AND d.sucursal_id = 2
--   AND d.siguiente_numero = 179;
--
-- COMMIT;

-- ============================================================================
-- OPCIÓN B (si la OC fue una prueba y NO debe existir):
--   Eliminar la OC y su detalle. El contador NO se toca (sigue en 179).
-- ============================================================================
-- START TRANSACTION;
--
-- DELETE FROM inv_orden_compra_detalles
-- WHERE compra_id = (SELECT id FROM inv_ordenes_compra WHERE numero_orden_compra = 'FLA-IND-0000133827');
--
-- DELETE FROM inv_ordenes_compra
-- WHERE numero_orden_compra = 'FLA-IND-0000133827';
--
-- COMMIT;
