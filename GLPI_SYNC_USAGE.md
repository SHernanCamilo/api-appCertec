# Guía de Uso - Sincronización de Activos GLPI

## Descripción General

El sistema de sincronización de activos GLPI permite mantener actualizada la información de equipos entre GLPI y la base de datos local de la matriz de obsolescencia.

## Funcionalidades Principales

### 1. Sincronización Completa (Primera Importación)
Importa todos los activos de GLPI por primera vez.

```bash
# Sincronización completa con verificación de eliminados
php artisan glpi:sync-activos --full-sync --force --check-deleted

# Con lotes más grandes para mejor rendimiento
php artisan glpi:sync-activos --full-sync --force --check-deleted --batch=50
```

### 2. Sincronización por Días (Automática)
Actualiza solo los activos que necesitan sincronización según los días configurados.

```bash
# Sincronizar activos no actualizados en los últimos 7 días
php artisan glpi:sync-activos --sync-days=7 --check-deleted

# Sincronizar activos no actualizados en los últimos 3 días
php artisan glpi:sync-activos --sync-days=3
```

### 3. Sincronización de Activo Específico
Actualiza un solo activo por su ID de GLPI.

```bash
# Sincronizar activo específico
php artisan glpi:sync-activos --single-asset=1478
```

### 4. Configuración de Cron Jobs
Configura la sincronización automática.

```bash
# Configurar cron diario con 7 días de sincronización
php artisan glpi:setup-cron --days=7 --schedule=daily

# Configurar cron semanal
php artisan glpi:setup-cron --days=14 --schedule=weekly
```

## API Endpoints

### Sincronización Forzada Completa
```http
POST /api/glpi/sync/force-all
Authorization: Bearer {token}
```

**Respuesta:**
```json
{
    "success": true,
    "message": "Sincronización completa iniciada correctamente",
    "data": {
        "type": "full_sync",
        "started_at": "2026-01-26T12:00:00.000000Z"
    }
}
```

### Sincronizar Activo Específico
```http
POST /api/glpi/sync/single-asset
Authorization: Bearer {token}
Content-Type: application/json

{
    "asset_id": 1478
}
```

**Respuesta:**
```json
{
    "success": true,
    "message": "Activo 1478 sincronizado correctamente",
    "data": {
        "type": "single_asset",
        "asset_id": 1478,
        "asset_data": {
            "id": 1,
            "id_activo_glpi": 1478,
            "nombre_equipo": "MDNP-PF4X26GT",
            "agente": "NVA",
            "detalles": {
                "procesador": "13th Gen Intel Core i5-1335U",
                "generacion_ram": "DDR5",
                "tamano_ram": 16
            }
        },
        "updated_at": "2026-01-26T12:00:00.000000Z"
    }
}
```

### Sincronización Automática
```http
POST /api/glpi/sync/auto
Authorization: Bearer {token}
Content-Type: application/json

{
    "sync_days": 7
}
```

### Obtener Estadísticas
```http
GET /api/glpi/sync/stats
Authorization: Bearer {token}
```

**Respuesta:**
```json
{
    "success": true,
    "data": {
        "total_assets": 150,
        "synced_today": 25,
        "synced_this_week": 80,
        "never_synced": 5,
        "deleted_assets": 3,
        "last_sync": "2026-01-26T10:30:00.000000Z",
        "assets_by_agent": [
            {"agente": "NVA", "count": 45},
            {"agente": "FLA", "count": 32}
        ]
    }
}
```

### Estado de Última Sincronización
```http
GET /api/glpi/sync/last-status
Authorization: Bearer {token}
```

## Parámetros del Comando

| Parámetro | Descripción | Valor por Defecto |
|-----------|-------------|-------------------|
| `--batch` | Número de activos por lote | 10 |
| `--offset` | Registro inicial | 0 |
| `--limit` | Límite total de registros | 1500 |
| `--force` | Forzar actualización | false |
| `--check-deleted` | Verificar activos eliminados | false |
| `--sync-days` | Días para sincronización | 7 |
| `--single-asset` | ID específico de activo | - |
| `--full-sync` | Sincronización completa | false |

## Ejemplos de Uso Práctico

### Escenario 1: Primera Implementación
```bash
# 1. Importar todos los activos por primera vez
php artisan glpi:sync-activos --full-sync --force --check-deleted --batch=30

# 2. Configurar cron para mantenimiento diario
php artisan glpi:setup-cron --days=7 --schedule=daily
```

### Escenario 2: Mantenimiento Regular
```bash
# Sincronización diaria automática (agregar al crontab)
0 2 * * * cd /path/to/project && php artisan glpi:sync-activos --sync-days=7 --check-deleted
```

### Escenario 3: Actualización de Activo Específico
```bash
# Desde línea de comandos
php artisan glpi:sync-activos --single-asset=1478

# Desde API (JavaScript)
fetch('/api/glpi/sync/single-asset', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({ asset_id: 1478 })
})
```

## Logs y Monitoreo

### Archivos de Log
- **Sincronización**: `storage/logs/ActivosGLPI.log`
- **Cron Jobs**: `storage/logs/cron-sync.log`
- **Laravel General**: `storage/logs/laravel.log`

### Monitoreo de Logs
```bash
# Ver logs de sincronización en tiempo real
tail -f storage/logs/ActivosGLPI.log

# Ver logs de cron
tail -f storage/logs/cron-sync.log

# Buscar errores específicos
grep "ERROR" storage/logs/ActivosGLPI.log
```

## Datos Sincronizados

### Tabla `matzobs_activos_c` (Datos Generales)
- ID de activo GLPI
- Nombre del equipo
- Tag del agente
- Placa de inventario
- Número de serie
- Ubicación
- Fecha de sincronización

### Tabla `matzobs_activos_d` (Detalles Técnicos)
- Marca y modelo
- Procesador y número de núcleos
- RAM (tamaño y generación)
- Discos (tipo, tamaño, interfaz)

## Optimizaciones de Rendimiento

El comando incluye múltiples optimizaciones para prevenir sobrecarga del sistema y GLPI:

### 1. Sistema de Caché
- **Cache de DeviceMemoryType**: Evita llamadas repetidas para obtener generaciones de RAM
- **Cache de DeviceProcessor**: Evita llamadas repetidas para obtener información de procesadores
- **Cache de Agent**: Evita llamadas repetidas para obtener tags de agentes
- Los caches se limpian automáticamente cada 5 lotes para liberar memoria

### 2. Throttling de API
- **Límite de llamadas**: Máximo 5 llamadas por segundo (configurable)
- **Pausa entre llamadas**: 0.2 segundos entre cada llamada API
- **Pausa entre lotes**: 3 segundos entre cada lote de procesamiento
- **Pausa de seguridad**: Cada 50 llamadas API, pausa adicional de 2 segundos

### 3. Sistema de Reintentos
- **Reintentos automáticos**: Hasta 3 intentos por cada llamada API fallida
- **Backoff exponencial**: Tiempo de espera creciente entre reintentos (1s, 2s, 4s)
- **Logging detallado**: Registra todos los errores y reintentos para diagnóstico

### 4. Gestión de Memoria
- **Monitoreo continuo**: Verifica el uso de memoria después de cada lote
- **Limpieza automática**: Si el uso de memoria supera el 80%, limpia caches automáticamente
- **Garbage collection**: Fuerza la recolección de basura al limpiar caches
- **Reportes**: Muestra memoria pico y memoria liberada en logs

### 5. Logging Optimizado
- **Canal dedicado**: Usa canal `glpi_sync` para logs específicos
- **Niveles apropiados**: Info para operaciones normales, Warning para problemas menores, Error para fallos
- **Métricas detalladas**: Registra duración, memoria usada, llamadas API realizadas

### Configuración de Optimización

Puedes ajustar los parámetros de optimización modificando estas propiedades en el comando:

```php
// En: app/Console/Commands/SincronizarActivosGlpi.php

protected $maxApiCallsPerSecond = 5;           // Llamadas API por segundo
protected $pauseBetweenBatches = 3;            // Segundos entre lotes
protected $pauseBetweenApiCalls = 200000;      // Microsegundos entre llamadas (0.2s)
protected $maxMemoryUsagePercent = 80;         // % máximo de memoria antes de limpiar
```

### Métricas de Rendimiento

Al finalizar la sincronización, el comando muestra:

```
🎉 Sincronización completada!
📈 Estadísticas finales:
   - Total procesados: 150
   - Nuevos creados: 5
   - Actualizados: 120
   - Omitidos: 25
⏱️  Tiempo total: 245 segundos
💾 Memoria pico: 128.5 MB
🔄 Total llamadas API: 487
```

Estas métricas también se registran en el log para análisis posterior.

## Solución de Problemas

### Error: "No se pudo conectar con GLPI"
1. Verificar configuración en `.env`:
   ```env
   GLPI_BASE_URL=https://your-glpi-server/apirest.php
   GLPI_USER_TOKEN=your-user-token
   GLPI_APP_TOKEN=your-app-token
   ```

2. Probar conexión:
   ```bash
   php artisan glpi:test-computer 1478
   ```

### Error: "Activos omitidos"
- Los activos se omiten si no han pasado los días configurados
- Usar `--force` para forzar actualización
- Verificar `--sync-days` parameter

### Rendimiento Lento
- Aumentar `--batch` size (ej: `--batch=50`)
- Reducir `--limit` para procesar en partes
- Ejecutar en horarios de menor carga

## Mejores Prácticas

1. **Primera Implementación**: Usar `--full-sync --force`
2. **Mantenimiento**: Configurar cron con `--sync-days=7`
3. **Actualizaciones Específicas**: Usar `--single-asset` desde la interfaz
4. **Monitoreo**: Revisar logs regularmente
5. **Rendimiento**: Ajustar `--batch` según el servidor