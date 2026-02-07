-- ============================================
-- SCRIPT PARA VERIFICAR Y CORREGIR PERMISOS
-- ============================================

-- 1. Verificar el usuario
SELECT 'PASO 1: Verificar Usuario' as paso;
SELECT id, name, email, created_at 
FROM users 
WHERE email = 'IKRAM1REZ@medilaser.com.co';

-- 2. Verificar roles del sistema
SELECT 'PASO 2: Roles disponibles' as paso;
SELECT id, nombre, codigo, es_admin, estado 
FROM seg_roles 
ORDER BY id;

-- 3. Verificar roles asignados al usuario
SELECT 'PASO 3: Roles del usuario' as paso;
SELECT 
    u.id as user_id,
    u.name as usuario,
    r.id as rol_id,
    r.nombre as rol,
    r.codigo as rol_codigo
FROM users u
LEFT JOIN seg_rol_user ru ON u.id = ru.user_id
LEFT JOIN seg_roles r ON ru.rol_id = r.id
WHERE u.email = 'IKRAM1REZ@medilaser.com.co';

-- 4. Verificar perfiles del rol "Super Administrador"
SELECT 'PASO 4: Perfiles del rol Super Administrador' as paso;
SELECT 
    r.id as rol_id,
    r.nombre as rol,
    p.id as perfil_id,
    p.nombre as perfil,
    p.codigo as perfil_codigo,
    m.nombre as modulo
FROM seg_roles r
LEFT JOIN seg_perfil_rol pr ON r.id = pr.rol_id
LEFT JOIN seg_perfiles p ON pr.perfil_id = p.id
LEFT JOIN seg_modulos m ON p.id_modulo = m.id
WHERE r.nombre = 'Super Administrador';

-- 5. Verificar permisos de los perfiles
SELECT 'PASO 5: Permisos de cada perfil' as paso;
SELECT 
    p.id as perfil_id,
    p.nombre as perfil,
    perm.id as permiso_id,
    perm.nombre as permiso,
    perm.codigo as permiso_codigo,
    perm.tipo as tipo,
    perm.estado as activo
FROM seg_perfiles p
INNER JOIN seg_permisos perm ON p.id_modulo = perm.id_modulo
WHERE p.id IN (
    SELECT perfil_id 
    FROM seg_perfil_rol 
    WHERE rol_id = (SELECT id FROM seg_roles WHERE nombre = 'Super Administrador')
)
ORDER BY p.nombre, perm.orden;

-- 6. RESUMEN: Permisos finales del usuario
SELECT 'PASO 6: RESUMEN - Permisos finales del usuario' as paso;
SELECT DISTINCT
    perm.codigo as codigo_permiso,
    perm.nombre as nombre_permiso,
    perm.tipo as tipo,
    m.nombre as modulo
FROM users u
INNER JOIN seg_rol_user ru ON u.id = ru.user_id
INNER JOIN seg_roles r ON ru.rol_id = r.id
INNER JOIN seg_perfil_rol pr ON r.id = pr.rol_id
INNER JOIN seg_perfiles p ON pr.perfil_id = p.id
INNER JOIN seg_permisos perm ON p.id_modulo = perm.id_modulo
INNER JOIN seg_modulos m ON perm.id_modulo = m.id
WHERE u.email = 'IKRAM1REZ@medilaser.com.co'
  AND perm.estado = 1
ORDER BY m.nombre, perm.orden;

-- ============================================
-- CORRECCIONES (Ejecutar solo si es necesario)
-- ============================================

-- Si el usuario NO tiene el rol asignado, ejecutar:
-- INSERT INTO seg_rol_user (user_id, rol_id, created_at, updated_at)
-- SELECT 
--     (SELECT id FROM users WHERE email = 'IKRAM1REZ@medilaser.com.co'),
--     (SELECT id FROM seg_roles WHERE nombre = 'Super Administrador'),
--     NOW(),
--     NOW()
-- WHERE NOT EXISTS (
--     SELECT 1 FROM seg_rol_user 
--     WHERE user_id = (SELECT id FROM users WHERE email = 'IKRAM1REZ@medilaser.com.co')
--     AND rol_id = (SELECT id FROM seg_roles WHERE nombre = 'Super Administrador')
-- );

-- Si el rol NO tiene perfiles asignados, necesitas asignarlos:
-- Ejemplo: Asignar el perfil "Crear Empresa" al rol "Super Administrador"
-- INSERT INTO seg_perfil_rol (rol_id, perfil_id, created_at, updated_at)
-- SELECT 
--     (SELECT id FROM seg_roles WHERE nombre = 'Super Administrador'),
--     (SELECT id FROM seg_perfiles WHERE nombre = 'Crear Empresa'),
--     NOW(),
--     NOW()
-- WHERE NOT EXISTS (
--     SELECT 1 FROM seg_perfil_rol 
--     WHERE rol_id = (SELECT id FROM seg_roles WHERE nombre = 'Super Administrador')
--     AND perfil_id = (SELECT id FROM seg_perfiles WHERE nombre = 'Crear Empresa')
-- );
