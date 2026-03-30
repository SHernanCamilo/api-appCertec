-- ============================================================================
-- SCRIPT DE CONFIGURACIÓN DE DATOS MAESTROS
-- Sistema de Anticipos y Motor de Flujos
-- ============================================================================
-- 
-- Este script configura los datos necesarios para que el sistema funcione:
--   1. Prefijos de sucursales
--   2. Niveles jerárquicos de cargos
--   3. Conceptos de anticipo (si no existen)
--
-- IMPORTANTE: Ejecutar DESPUÉS de las migraciones
-- ============================================================================

-- ============================================================================
-- 1. CONFIGURAR PREFIJOS DE SUCURSALES
-- ============================================================================

-- Actualizar prefijos según la estructura organizacional
-- Ajustar los nombres según tu base de datos real

UPDATE config_ubi_sucursales 
SET prefijo = 'MA' 
WHERE nombre LIKE '%Nacional%' OR nombre LIKE '%Matriz%' OR nombre LIKE '%Bogotá%';

UPDATE config_ubi_sucursales 
SET prefijo = 'NVA' 
WHERE nombre LIKE '%Neiva%';

UPDATE config_ubi_sucursales 
SET prefijo = 'EAL' 
WHERE nombre LIKE '%Eje Amazonico%' OR nombre LIKE '%Florencia%';

UPDATE config_ubi_sucursales 
SET prefijo = 'TJA' 
WHERE nombre LIKE '%Tumaco%';

UPDATE config_ubi_sucursales 
SET prefijo = 'FLA' 
WHERE nombre LIKE '%Florencia%';

-- Verificar prefijos asignados
SELECT id, nombre, prefijo 
FROM config_ubi_sucursales 
ORDER BY prefijo;

-- ============================================================================
-- 2. ASIGNAR NIVELES JERÁRQUICOS A CARGOS
-- ============================================================================

-- Nivel 1: Estratégico / Directivo
UPDATE config_cargo 
SET nivel_jerarquico = 1 
WHERE nombre_cargo LIKE '%Presidente%'
   OR nombre_cargo LIKE '%Vicepresidente%'
   OR nombre_cargo LIKE '%Gerente%'
   OR nombre_cargo LIKE '%Director%'
   OR nombre_cargo LIKE '%Médico Especialista%';

-- Nivel 2: Táctico (I y II)
UPDATE config_cargo 
SET nivel_jerarquico = 2 
WHERE nombre_cargo LIKE '%Coordinador%'
   OR nombre_cargo LIKE '%Jefe%'
   OR nombre_cargo LIKE '%Analista%'
   OR nombre_cargo LIKE '%Profesional%';

-- Nivel 3: Operativo (I y II) - Por defecto
UPDATE config_cargo 
SET nivel_jerarquico = 3 
WHERE nivel_jerarquico IS NULL 
   OR nivel_jerarquico = 0
   OR nombre_cargo LIKE '%Auxiliar%'
   OR nombre_cargo LIKE '%Asistente%'
   OR nombre_cargo LIKE '%Técnico%'
   OR nombre_cargo LIKE '%Tecnólogo%';

-- Verificar niveles asignados
SELECT nivel_jerarquico, COUNT(*) as cantidad, 
       GROUP_CONCAT(nombre_cargo SEPARATOR ', ') as ejemplos
FROM config_cargo 
GROUP BY nivel_jerarquico
ORDER BY nivel_jerarquico;

-- ============================================================================
-- 3. CREAR CONCEPTOS DE ANTICIPO (SI NO EXISTEN)
-- ============================================================================

-- Verificar si existen tipos, clases, modalidades y conceptos
SELECT 'Tipos existentes:' as info, COUNT(*) as cantidad FROM anti_tipos;
SELECT 'Clases existentes:' as info, COUNT(*) as cantidad FROM anti_clases;
SELECT 'Modalidades existentes:' as info, COUNT(*) as cantidad FROM anti_modalidades;
SELECT 'Conceptos existentes:' as info, COUNT(*) as cantidad FROM anti_conceptos;

-- Si no existen, crear la estructura básica:

-- Tipo: Viáticos
INSERT IGNORE INTO anti_tipos (nombre, descripcion, estado, created_at, updated_at)
VALUES ('Viáticos', 'Gastos de viaje y desplazamiento', 1, NOW(), NOW());

SET @id_tipo_viaticos = LAST_INSERT_ID();

-- Clase: Nacional
INSERT IGNORE INTO anti_clases (id_tipo, nombre, descripcion, estado, created_at, updated_at)
VALUES (@id_tipo_viaticos, 'Nacional', 'Viajes dentro del territorio nacional', 1, NOW(), NOW());

SET @id_clase_nacional = LAST_INSERT_ID();

-- Modalidad: Terrestre
INSERT IGNORE INTO anti_modalidades (id_clase, nombre, descripcion, estado, created_at, updated_at)
VALUES (@id_clase_nacional, 'Terrestre', 'Desplazamiento terrestre', 1, NOW(), NOW());

SET @id_modalidad_terrestre = LAST_INSERT_ID();

-- Conceptos
INSERT IGNORE INTO anti_conceptos (id_modalidad, nombre, descripcion, estado, created_at, updated_at)
VALUES 
(@id_modalidad_terrestre, 'Alimentación Nacional', 'Gastos de alimentación en viajes nacionales', 1, NOW(), NOW()),
(@id_modalidad_terrestre, 'Transporte Nacional', 'Gastos de transporte interno en destino', 1, NOW(), NOW()),
(@id_modalidad_terrestre, 'Alojamiento Nacional', 'Gastos de hospedaje', 1, NOW(), NOW());

-- Verificar conceptos creados
SELECT c.id, c.nombre, m.nombre as modalidad, cl.nombre as clase, t.nombre as tipo
FROM anti_conceptos c
JOIN anti_modalidades m ON c.id_modalidad = m.id
JOIN anti_clases cl ON m.id_clase = cl.id
JOIN anti_tipos t ON cl.id_tipo = t.id
WHERE c.estado = 1;

-- ============================================================================
-- 4. VERIFICAR ESTRUCTURA COMPLETA
-- ============================================================================

-- Resumen de configuración
SELECT 
    'Sucursales con prefijo' as item,
    COUNT(*) as cantidad
FROM config_ubi_sucursales 
WHERE prefijo IS NOT NULL

UNION ALL

SELECT 
    'Cargos con nivel jerárquico' as item,
    COUNT(*) as cantidad
FROM config_cargo 
WHERE nivel_jerarquico IS NOT NULL

UNION ALL

SELECT 
    'Ciudades clasificadas' as item,
    COUNT(*) as cantidad
FROM anti_ciudades

UNION ALL

SELECT 
    'Conceptos activos' as item,
    COUNT(*) as cantidad
FROM anti_conceptos 
WHERE estado = 1

UNION ALL

SELECT 
    'Reglas de topes' as item,
    COUNT(*) as cantidad
FROM anti_reglas 
WHERE estado = 1

UNION ALL

SELECT 
    'Flujos configurados' as item,
    COUNT(*) as cantidad
FROM wf_definiciones 
WHERE estado = 1;

-- ============================================================================
-- 5. DATOS DE PRUEBA (OPCIONAL)
-- ============================================================================

-- Crear una unidad funcional de prueba
INSERT IGNORE INTO anti_unidades_funcionales (codigo, nombre, id_empresa, estado, created_at, updated_at)
VALUES ('UF_ADMIN', 'Unidad Administrativa', NULL, 1, NOW(), NOW());

-- Nota: Los aprobadores deben configurarse manualmente según los usuarios reales del sistema

-- ============================================================================
-- FIN DEL SCRIPT
-- ============================================================================

-- Para ejecutar este script:
-- mysql -u usuario -p nombre_base_datos < configurar_datos_maestros.sql

-- O desde MySQL Workbench / phpMyAdmin:
-- Copiar y pegar secciones según sea necesario