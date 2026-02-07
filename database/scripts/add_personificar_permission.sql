-- ============================================
-- SCRIPT PARA AGREGAR PERMISO DE PERSONIFICAR
-- ============================================

-- Insertar el permiso de personificar en el módulo de Gestión de Usuarios (ID: 15)
INSERT INTO `seg_permisos` (`id_modulo`, `nombre`, `codigo`, `descripcion`, `tipo`, `icono`, `orden`, `estado`, `created_at`, `updated_at`) 
VALUES (
    15, 
    'Personificar Usuario', 
    'org-personificar', 
    'Permite actuar como otro usuario del sistema (similar a GLPI)', 
    'boton', 
    'user-secret', 
    10, 
    1, 
    NOW(), 
    NOW()
);

-- Verificar que se insertó correctamente
SELECT 
    p.id,
    p.nombre,
    p.codigo,
    p.descripcion,
    p.tipo,
    p.icono,
    m.nombre as modulo_nombre
FROM seg_permisos p
INNER JOIN seg_modulos m ON p.id_modulo = m.id
WHERE p.codigo = 'org-personificar';

-- Mostrar todos los permisos del módulo de usuarios para verificar
SELECT 
    p.id,
    p.nombre,
    p.codigo,
    p.tipo,
    p.orden,
    p.estado
FROM seg_permisos p
WHERE p.id_modulo = 15
ORDER BY p.orden;