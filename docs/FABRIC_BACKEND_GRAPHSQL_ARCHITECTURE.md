# 🏗️ Arquitectura Backend (Laravel) ↔ Graph-Fabric (Python)

**Fecha:** 7 de Julio de 2026  
**Versión:** 1.0  
**Autores:** Equipo TIC Medilaser

---

## 📐 Diagrama de Flujo General

```
┌──────────────┐       ┌──────────────────────┐       ┌───────────────────────┐
│   Angular    │──JWT──▶│  Laravel (Gateway)   │──HTTP──▶│  Graph-Fabric (Python)│
│  (Frontend)  │◀─JSON─│  api-appCertec       │◀─JSON─│  Puerto 8001          │
└──────────────┘       └──────────────────────┘       └───────────────────────┘
                              │                              │
                              │ TOKEN_ADMIN                   │ ODBC Driver 18
                              │ (servicio)                    │
                              ▼                              ▼
                        ┌─────────┐                 ┌──────────────────┐
                        │  MySQL  │                 │ Microsoft Fabric │
                        │ (users, │                 │ (Lakehouse SQL)  │
                        │ config) │                 └──────────────────┘
                        └─────────┘                          │
                                                             ▼
                                                      ┌─────────────┐
                                                      │    Redis     │
                                                      │ (cache TTL)  │
                                                      └─────────────┘
```

---

## 🔑 Responsabilidades de cada capa

| Capa | Responsabilidad |
|------|----------------|
| **Angular** | Autenticación Azure AD, presentación, traducción visual, filtros UX |
| **Laravel** | Validar JWT, resolver permisos (users_grups), normalizar fechas, proxy seguro, rate limit, circuit breaker |
| **Graph-Fabric (Python)** | Conectar a Fabric via ODBC, ejecutar queries, detectar vistas pesadas, cache Redis, export |
| **Microsoft Fabric** | Almacenamiento y procesamiento de datos (Lakehouse, SQL Analytics) |
| **Redis** | Cache de queries (TTL 30s-10min), conteo de filas (TTL 30min) |

---

## 🔐 Seguridad — Flujo de Autenticación

```
1. Angular → Azure AD → JWT (con groups, UPN)
2. Angular → Laravel (Authorization: Bearer JWT)
3. Laravel verifica JWT + lee users_grups (grupos GG-BD-*, departamento)
4. Laravel envía a Python: TOKEN_ADMIN + contexto del usuario (groups, department, email)
5. Python valida TOKEN_ADMIN y usa el contexto para resolver esquemas permitidos
```

**Variables de entorno clave (Laravel `.env`):**
```env
TOKEN_ADMIN=x-TFn0qlkX-fF50SIFHlgc12aICx_oGPQcGDCxfdgfI   # Token de servicio
GRAPHQL_URL=http://127.0.0.1:8001                             # URL API Python
GRAPHQL_TIMEOUT=125                                            # Timeout HTTP (seg)
GRAPHQL_API_KEY=g2H9jK3mP8vN5bC7xZ4qL1wR6tY0sF3d             # Header X-API-Key
```

**Variables de entorno clave (Python `.env`):**
```env
API_SECRET_KEY=UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P
TOKEN_ADMIN=UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P
FABRIC_COMMAND_TIMEOUT=120
HEAVY_THRESHOLD=1000000
```

---

## 📂 Archivos clave en Laravel

| Archivo | Función |
|---------|---------|
| `app/Services/Fabric/GraphFabricGatewayService.php` | Servicio principal — proxy a la API Python |
| `app/Http/Controllers/Fabric/FabricViewerController.php` | Controller REST — endpoints del viewer |
| `app/Http/Middleware/FabricRateLimiter.php` | Rate limiting por usuario |
| `app/Services/Fabric/FabricCircuitBreaker.php` | Circuit breaker (si Python no responde) |
| `app/Jobs/FabricExportJob.php` | Export async en background (queue) |

---

## 🔄 Endpoints Laravel → Python

| Endpoint Laravel | Método | Endpoint Python | Descripción |
|------------------|--------|-----------------|-------------|
| `/api/fabric/viewer/views` | POST | `/api/catalog/views` | Catálogo de vistas permitidas |
| `/api/fabric/viewer/columns` | POST | `/api/catalog/columns` | Columnas con tipos de una vista |
| `/api/fabric/viewer/data` | POST | `/api/data/dynamic` | Datos paginados con filtros |
| `/api/fabric/viewer/export` | POST | `/api/data/export/excel` | Export síncrono (descarga) |
| `/api/fabric/viewer/export/start` | POST | `/api/data/export/excel` | Export async (queue job) |
| `/api/fabric/viewer/export/status/{id}` | GET | — | Estado del export (Laravel) |
| `/api/fabric/viewer/export/download/{id}` | GET | — | Descarga del export (Laravel) |
| `/api/fabric/viewer/context` | GET | — | Info del usuario (debug) |

---

## 🛡️ Resiliencia implementada en Laravel

### 1. Circuit Breaker (`FabricCircuitBreaker`)

```
Estado CLOSED → Funciona normal
  ↓ 10 fallos consecutivos (FABRIC_CB_THRESHOLD=10)
Estado OPEN → Rechaza inmediatamente (503) durante 30s (FABRIC_CB_TIMEOUT=30)
  ↓ Pasan 30 segundos
Estado HALF-OPEN → Permite 1 request de prueba
  ↓ Si funciona → CLOSED | Si falla → OPEN de nuevo
```

### 2. Rate Limiter (por usuario)

| Operación | Límite |
|-----------|--------|
| `data` (queries) | 60 req/min |
| `export` | 10 req/min |
| `columns` | 30 req/min |
| `views` | 30 req/min |

### 3. Cache de queries

```php
// Misma consulta exacta → respuesta de Redis (30s TTL)
$cacheKey = 'fabric_qry:' . md5(json_encode($payload));
$cacheTtl = env('FABRIC_QUERY_CACHE_TTL', 30);
```

### 4. Timeout HTTP

```
Laravel timeout: 125s (GRAPHQL_TIMEOUT=125)
Python timeout:  120s (FABRIC_COMMAND_TIMEOUT=120)
                  ↑ Laravel espera 5s más que Python
```

---

## 📅 Normalización de Fechas (Laravel → Python → SQL Server)

**Problema:** SQL Server (ODBC 22007) falla si recibe fechas en formato local (`06/07/2026`).

**Solución en `GraphFabricGatewayService::normalizeFilters()`:**

```
Formato entrada             → Formato salida (ISO)
─────────────────────────────────────────────────
06/07/2026                  → 2026-07-06
6/7/2026                    → 2026-07-06
06-07-2026                  → 2026-07-06
06/07/2026 14:30            → 2026-07-06 14:30:00
2026-07-06                  → 2026-07-06 (sin cambio)
2026-07-06T12:00:00         → 2026-07-06T12:00:00 (sin cambio)
%AMOX%                      → %AMOX% (sin cambio, es texto LIKE)
ACTIVO                      → ACTIVO (sin cambio)
{from: "01/07/2026", to: "31/07/2026"} → {from: "2026-07-01", to: "2026-07-31"}
```

**Regla:** si pese a normalizar sigue el error 22007, el problema está en los datos de la vista del Lakehouse (columna fecha con strings inválidos). Eso lo corrige el equipo de Fabric.

---

## 🚦 Detección Dinámica de Vistas Pesadas

### Flujo (manejado 100% por la API Python)

```
Request #1 a una vista nueva:
  Python: No tengo conteo en Redis → ejecuto normal
  Background: COUNT(*) → guarda en Redis (TTL 30min)

Request #2 en adelante:
  Python: Redis dice 7,482,735 filas → HEAVY
  ¿Tiene filtros activos?
    SÍ → Ejecuta normal (con skip_count automático, cache 10min)
    NO → HTTP 422 "filters_required" con sugerencias
```

### Respuesta HTTP 422 de Python (que Laravel propaga)

```json
{
  "error": "filters_required",
  "message": "La vista 'ca.VW_Portfolio_ExtractoCartera' contiene mas de 1,000,000 registros...",
  "heavy_view": true,
  "suggestions": ["FechaFactura", "Nit", "TipoDocumento"],
  "columns": [
    {"name": "Nit", "type": "varchar"},
    {"name": "FechaFactura", "type": "datetime2"}
  ]
}
```

### Estado actual en Laravel (7 Jul 2026)

El controlador `FabricViewerController::data()` ya NO tiene lista estática. Depende 100% de la detección dinámica de Python: si la API responde 422 con `filters_required`, Laravel lo propaga al frontend con `suggestions[]` y `columns[]` (con tipos).

El método `post()` en `GraphFabricGatewayService` captura el HTTP 422 y retorna una estructura con `__filters_required => true` que `queryViewData()` transforma en la respuesta final.

---

## 📊 Tipos de Columnas

El endpoint `/api/catalog/columns` devuelve los tipos SQL de cada columna:

```json
{
  "columns": [
    {"name": "Nit", "type": "varchar", "nullable": true},
    {"name": "FechaFactura", "type": "datetime2", "nullable": true},
    {"name": "Monto", "type": "decimal", "nullable": true},
    {"name": "Activo", "type": "bit", "nullable": false}
  ]
}
```

**Uso por capa:**
- **Python:** devuelve los tipos del catálogo de Fabric
- **Laravel:** los pasa al frontend sin transformar
- **Angular:** usa los tipos para renderizar inputs de filtro (datepicker, number, text, toggle)

---

## 🐛 Errores comunes y diagnóstico

| Error | Origen | Causa | Solución |
|-------|--------|-------|----------|
| `cURL error 28: timed out after 60001ms` | Laravel→Python | Timeout Laravel < timeout Python | Subir `GRAPHQL_TIMEOUT` (ahora 125) |
| `503 "Conversion failed... date/time"` | ODBC Driver 18 / SQL Server | Filtro con fecha mal formateada O datos inválidos en la vista | Normalización de fechas (ya implementada). Si persiste sin filtros: problema en la vista Lakehouse |
| `422 "filters_required"` | Python (deliberado) | Vista con >1M filas sin filtros | Normal — mostrar panel de filtros en Angular |
| `503 circuit breaker` | Laravel (deliberado) | Python no respondió 10 veces consecutivas | Esperar 30s, se auto-recupera |
| `429 rate limit` | Laravel (deliberado) | Más de 60 req/min de un usuario | Esperar `retry_after` |
| `403 "Sin acceso al esquema"` | Laravel | Usuario sin grupo GG-BD-{schema} | Asignar grupo en users_grups |
| `"Token inválido"` | Python | El token en el body no coincide con `TOKEN_ADMIN` | Verificar `.env` en Python vs Laravel |

---

## 🔧 Variables de entorno — Producción (VPS)

### Laravel (`/home/medadminvps/api-appCertec/.env`)

```env
GRAPHQL_URL=http://127.0.0.1:8001
GRAPHQL_TIMEOUT=125
GRAPHQL_API_KEY=g2H9jK3mP8vN5bC7xZ4qL1wR6tY0sF3d
TOKEN_ADMIN=x-TFn0qlkX-fF50SIFHlgc12aICx_oGPQcGDCxfdgfI
FABRIC_CB_THRESHOLD=10
FABRIC_CB_TIMEOUT=30
FABRIC_QUERY_CACHE_TTL=30
```

### Python (`/home/medadminvps/Graph-Fabric/.env`)

```env
API_SECRET_KEY=UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P
TOKEN_ADMIN=UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P
FABRIC_COMMAND_TIMEOUT=120
FABRIC_CONNECTION_TIMEOUT=30
HEAVY_THRESHOLD=1000000
REDIS_TTL_ROW_COUNT=1800
REDIS_TTL_HEAVY=600
```

**Nota:** Los `TOKEN_ADMIN` son distintos entre Laravel y Python. Laravel envía SU token al campo `token` del payload. Python valida contra SU `TOKEN_ADMIN`. En producción el valor en `.env` de Laravel NO es el mismo que el de Python (son tokens independientes que cada uno valida). El de producción de Laravel está en la VPS.

---

## ✅ Checklist de implementación — Estado actual

| # | Tarea | Estado |
|---|-------|--------|
| 1 | `skip_count` en queries | ✅ Implementado |
| 2 | Timeout 125s (antes 60s) | ✅ Implementado (7 Jul) |
| 3 | Normalización robusta de fechas (ISO) | ✅ Implementado (7 Jul) |
| 4 | Circuit breaker | ✅ Implementado |
| 5 | Rate limiter 60 req/min | ✅ Implementado |
| 6 | Cache de queries (30s TTL) | ✅ Implementado |
| 7 | Export async con job | ✅ Implementado |
| 8 | Propagar HTTP 422 `filters_required` de Python | ✅ Implementado (7 Jul) |
| 9 | Eliminar lista estática `$heavyViews` | ✅ Implementado (7 Jul) |
| 10 | Propagar `heavy_view` de `page_info` al frontend | ✅ Implementado (7 Jul) |
| 11 | SSE (Server-Sent Events) para queries largas | 💡 Propuesta — no iniciado |

---

## 🔮 Próximos pasos

1. **Manejo del 422 en `post()`:** Capturar el body JSON del HTTP 422 y retornarlo como estructura en vez de `null`. Esto permite eliminar la lista estática y depender de la detección dinámica de Python.

2. **SSE (Server-Sent Events):** Para queries que tardan 30-60s, mantener la conexión abierta con eventos de progreso. El frontend muestra una barra de progreso en vez de un spinner indefinido. Requiere cambios en los 3 lados (Python + Laravel + Angular).

3. **Optimizaciones Fabric (otro equipo):** Z-ORDER, V-Order y particionamiento en tablas pesadas para bajar tiempos de 60s a 3s.

---

*Última actualización: 7 de Julio de 2026*
