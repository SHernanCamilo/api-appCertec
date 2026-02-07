# Asignación Automática de Empresa, Sede y Sucursal por TAG de Agente

## Descripción

El comando de sincronización GLPI ahora asigna automáticamente la empresa, sede y sucursal a cada activo basándose en el TAG del agente GLPI, utilizando la tabla `matzobs_agentes` como referencia.

## Funcionamiento

### 1. Tabla de Parámetros: `matzobs_agentes`

Esta tabla contiene la parametrización de los TAGs de agentes GLPI con sus respectivas empresas, sedes y sucursales:

```sql
CREATE TABLE matzobs_agentes (
    id BIGINT PRIMARY KEY,
    tag VARCHAR(100) UNIQUE,           -- TAG del agente GLPI (ej: "NVA", "FLA", "BOG")
    id_empresa BIGINT,                 -- ID de la empresa
    id_sucursal BIGINT,                -- ID de la sucursal
    id_sede BIGINT NULLABLE,           -- ID de la sede (opcional)
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### 2. Proceso de Asignación

Cuando el comando sincroniza un activo desde GLPI:

1. **Extrae el TAG** del agente GLPI del equipo
2. **Busca el TAG** en la tabla `matzobs_agentes`
3. **Si encuentra el TAG**:
   - Asigna `id_empresa`, `id_sede` y `id_sucursal` del registro encontrado
   - Incrementa contador `agents_found`
   - Registra en log: "Parámetros de agente encontrados"
4. **Si NO encuentra el TAG**:
   - Usa valores por defecto: `id_empresa=1`, `id_sede=1`, `id_sucursal=null`
   - Incrementa contador `agents_not_found`
   - Registra warning en log: "TAG no encontrado, usando valores por defecto"

### 3. Sistema de Caché

Para optimizar el rendimiento, el comando implementa un caché de parámetros de agentes:

```php
protected $agentParamsCache = [];
```

- La primera vez que encuentra un TAG, lo busca en la base de datos
- Guarda el resultado en caché (encontrado o no encontrado)
- Las siguientes veces que encuentra el mismo TAG, usa el caché
- El caché se limpia cada 5 lotes para liberar memoria

## Configuración Inicial

### Paso 1: Parametrizar TAGs en la Base de Datos

Antes de ejecutar la sincronización, debes registrar los TAGs de tus agentes GLPI:

```sql
-- Ejemplo: Insertar TAGs de agentes
INSERT INTO matzobs_agentes (tag, id_empresa, id_sucursal, id_sede) VALUES
('NVA', 1, 5, 2),   -- TAG "NVA" -> Empresa 1, Sucursal 5, Sede 2
('FLA', 1, 3, 1),   -- TAG "FLA" -> Empresa 1, Sucursal 3, Sede 1
('BOG', 2, 8, 4),   -- TAG "BOG" -> Empresa 2, Sucursal 8, Sede 4
('MED', 2, 9, 5);   -- TAG "MED" -> Empresa 2, Sucursal 9, Sede 5
```

### Paso 2: Verificar TAGs Existentes

Puedes consultar los TAGs ya parametrizados:

```sql
SELECT tag, id_empresa, id_sucursal, id_sede 
FROM matzobs_agentes 
ORDER BY tag;
```

## Uso del Comando

### Sincronización Normal

```bash
php artisan glpi:sync-activos --batch=10 --sync-days=7
```

El comando automáticamente:
- Extrae el TAG de cada equipo
- Busca el TAG en `matzobs_agentes`
- Asigna empresa, sede y sucursal según la parametrización

### Sincronización Completa

```bash
php artisan glpi:sync-activos --full-sync --force
```

### Sincronización de Activo Específico

```bash
php artisan glpi:sync-activos --single-asset=1478
```

## Estadísticas y Reportes

Al finalizar la sincronización, el comando muestra estadísticas de asignación:

```
🎉 Sincronización completada!
📈 Estadísticas finales:
   - Total procesados: 150
   - Nuevos creados: 5
   - Actualizados: 120
   - Omitidos: 25

📋 Asignación de Agentes:
   - TAGs encontrados en BD: 145
   - TAGs no encontrados (valores por defecto): 5

⏱️  Tiempo total: 245 segundos
💾 Memoria pico: 128.5 MB
🔄 Total llamadas API: 487
```

### Interpretación de Estadísticas

- **TAGs encontrados en BD**: Activos asignados correctamente según parametrización
- **TAGs no encontrados**: Activos que usaron valores por defecto (requieren parametrización)

## Logs Detallados

### TAG Encontrado

```json
{
  "level": "debug",
  "message": "Parámetros de agente encontrados para TAG 'NVA'",
  "context": {
    "tag": "NVA",
    "id_empresa": 1,
    "id_sede": 2,
    "id_sucursal": 5
  }
}
```

### TAG No Encontrado

```json
{
  "level": "warning",
  "message": "TAG 'XXX' no encontrado en matzobs_agentes, usando valores por defecto",
  "context": {
    "tag": "XXX",
    "default_empresa": 1,
    "default_sede": 1
  }
}
```

## Identificar TAGs No Parametrizados

### Consulta SQL para TAGs sin Parametrizar

```sql
-- Activos con valores por defecto (posiblemente sin parametrizar)
SELECT DISTINCT agente, COUNT(*) as cantidad
FROM matzobs_activos_c
WHERE id_empresa = 1 
  AND id_sede = 1 
  AND id_sucursal IS NULL
  AND usuario_modificacion = 'GLPI_SYNC'
GROUP BY agente
ORDER BY cantidad DESC;
```

### Revisar Logs

```bash
# Buscar TAGs no encontrados en los logs
grep "TAG.*no encontrado" storage/logs/ActivosGLPI.log

# Ver todos los TAGs procesados
grep "Parámetros de agente" storage/logs/ActivosGLPI.log
```

## Agregar Nuevos TAGs

### Opción 1: Manualmente via SQL

```sql
INSERT INTO matzobs_agentes (tag, id_empresa, id_sucursal, id_sede, created_at, updated_at)
VALUES ('NUEVO_TAG', 1, 5, 2, NOW(), NOW());
```

### Opción 2: Via API (si existe endpoint)

```bash
curl -X POST http://your-api/api/matriz-obs-agentes \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "tag": "NUEVO_TAG",
    "id_empresa": 1,
    "id_sucursal": 5,
    "id_sede": 2
  }'
```

### Opción 3: Via Interfaz Web

Si existe un módulo de administración en la aplicación web, puedes agregar TAGs desde ahí.

## Re-sincronizar Después de Parametrizar

Después de agregar nuevos TAGs a `matzobs_agentes`, puedes re-sincronizar los activos:

```bash
# Re-sincronizar todos los activos forzando actualización
php artisan glpi:sync-activos --full-sync --force

# O re-sincronizar solo activos con valores por defecto
# (requeriría una consulta SQL previa para obtener los IDs)
```

## Valores por Defecto

Si un TAG no se encuentra en `matzobs_agentes`, se usan estos valores:

```php
[
    'id_empresa' => 1,
    'id_sede' => 1,
    'id_sucursal' => null
]
```

**Recomendación**: Parametriza todos los TAGs antes de la sincronización inicial para evitar asignaciones incorrectas.

## Validación de Datos

### Verificar Asignaciones Correctas

```sql
-- Ver distribución de activos por empresa y TAG
SELECT 
    a.agente as TAG,
    a.id_empresa,
    e.nombre as empresa,
    COUNT(*) as cantidad_activos
FROM matzobs_activos_c a
LEFT JOIN ent_empresas e ON a.id_empresa = e.id
WHERE a.usuario_modificacion = 'GLPI_SYNC'
GROUP BY a.agente, a.id_empresa, e.nombre
ORDER BY a.agente;
```

### Verificar TAGs Parametrizados vs Usados

```sql
-- TAGs en uso que NO están parametrizados
SELECT DISTINCT a.agente
FROM matzobs_activos_c a
WHERE a.agente NOT IN (SELECT tag FROM matzobs_agentes)
  AND a.usuario_modificacion = 'GLPI_SYNC';
```

## Troubleshooting

### Problema: Todos los activos tienen empresa=1, sede=1

**Causa**: Los TAGs no están parametrizados en `matzobs_agentes`

**Solución**:
1. Identificar TAGs únicos: `SELECT DISTINCT agente FROM matzobs_activos_c`
2. Parametrizar cada TAG en `matzobs_agentes`
3. Re-sincronizar con `--force`

### Problema: Algunos activos tienen asignación incorrecta

**Causa**: TAG parametrizado con datos incorrectos

**Solución**:
1. Corregir en `matzobs_agentes`: `UPDATE matzobs_agentes SET id_empresa=X WHERE tag='YYY'`
2. Re-sincronizar: `php artisan glpi:sync-activos --force`

### Problema: TAG no se extrae correctamente de GLPI

**Causa**: El TAG no está en los campos esperados de GLPI

**Solución**: Revisar logs para ver qué TAG se está extrayendo:
```bash
grep "Tag del agente encontrado" storage/logs/ActivosGLPI.log
grep "Tag obtenido de" storage/logs/ActivosGLPI.log
```

## Mejores Prácticas

1. **Parametrizar antes de sincronizar**: Registra todos los TAGs conocidos antes de la primera sincronización
2. **Revisar logs regularmente**: Identifica TAGs no parametrizados en los warnings
3. **Validar asignaciones**: Ejecuta consultas SQL para verificar que las asignaciones son correctas
4. **Documentar TAGs**: Mantén un documento con el significado de cada TAG (ubicación, propósito, etc.)
5. **Sincronización incremental**: Usa `--sync-days` para sincronizaciones regulares, `--full-sync` solo cuando sea necesario

## Ejemplo Completo

```bash
# 1. Parametrizar TAGs
mysql -u user -p database << EOF
INSERT INTO matzobs_agentes (tag, id_empresa, id_sucursal, id_sede) VALUES
('NVA', 1, 5, 2),
('FLA', 1, 3, 1),
('BOG', 2, 8, 4);
EOF

# 2. Sincronización inicial completa
php artisan glpi:sync-activos --full-sync --force --check-deleted

# 3. Verificar resultados
mysql -u user -p database << EOF
SELECT agente, id_empresa, COUNT(*) 
FROM matzobs_activos_c 
GROUP BY agente, id_empresa;
EOF

# 4. Revisar TAGs no encontrados
grep "no encontrado" storage/logs/ActivosGLPI.log

# 5. Sincronización diaria (agregar a cron)
0 2 * * * cd /path/to/project && php artisan glpi:sync-activos --sync-days=7
```

## Conclusión

La asignación automática por TAG simplifica la gestión de activos al:
- Eliminar asignaciones manuales de empresa/sede/sucursal
- Centralizar la parametrización en una tabla
- Proporcionar trazabilidad completa via logs
- Optimizar rendimiento con sistema de caché
- Facilitar auditorías y validaciones

Asegúrate de parametrizar todos los TAGs antes de la sincronización inicial para obtener los mejores resultados.
