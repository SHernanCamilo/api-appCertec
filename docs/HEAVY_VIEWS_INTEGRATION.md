# Heavy Views — Flujo de Integración Angular + Laravel + Graph-Fabric

## Resumen

Las vistas con más de 1 millón de registros (ej: `ca.VW_Portfolio_ExtractoCartera` con 7.4M filas) tardan 60-90 segundos en responder sin filtros. Para evitar esto, Graph-Fabric **detecta dinámicamente** cuántas filas tiene una vista y exige filtros cuando supera el umbral.

---

## Diagrama de Flujo Completo

```
┌─────────────┐     ┌──────────────┐     ┌────────────────────┐     ┌────────────┐
│   Angular   │────▶│    Laravel    │────▶│   Graph-Fabric     │────▶│   Fabric   │
│  (Frontend) │◀────│   (Gateway)  │◀────│   (Python API)     │◀────│   (F16)    │
└─────────────┘     └──────────────┘     └────────────────────┘     └────────────┘
```

### Escenario A: Vista normal (<1M filas) — Sin cambios

```
Angular → Laravel: "Quiero datos de aa.VW_AG_Agendas sin filtros"
Laravel → Graph-Fabric: POST /api/data/dynamic (sin filtros)
Graph-Fabric:
  1. get_row_count_fast() → Redis dice 2,250,518 (< 1M umbral)
  2. Ejecuta query normal → 200 OK con datos
Laravel → Angular: datos normales
```

### Escenario B: Vista pesada (>1M filas) SIN filtros — Bloquea

```
Angular → Laravel: "Quiero datos de ca.VW_Portfolio_ExtractoCartera sin filtros"
Laravel → Graph-Fabric: POST /api/data/dynamic (sin filtros)
Graph-Fabric:
  1. get_row_count_fast() → Redis dice 7,482,735 (> 1M umbral)
  2. No hay filtros activos → HTTP 422
Laravel recibe 422 → Devuelve a Angular indicando que se requieren filtros
Angular: Muestra panel de filtros obligatorios con sugerencias
```

### Escenario C: Vista pesada (>1M filas) CON filtros — Pasa normal

```
Angular → Laravel: "Datos de ExtractoCartera con Nit=900156264"
Laravel → Graph-Fabric: POST /api/data/dynamic (filters: {Nit: "900156264"})
Graph-Fabric:
  1. get_row_count_fast() → 7,482,735 (heavy=true)
  2. TIENE filtros activos → ejecuta query (skip_count automático)
  3. HTTP 200 con datos + page_info.heavy_view=true
Laravel → Angular: datos + indicador de vista pesada
```

---

## Respuesta HTTP 422 de Graph-Fabric

Cuando una vista supera el `HEAVY_THRESHOLD` (1,000,000 filas) y no tiene filtros activos:

```json
{
  "error": "filters_required",
  "message": "La vista 'ca.VW_Portfolio_ExtractoCartera' contiene mas de 1,000,000 registros. Para evitar tiempos de espera excesivos (>60s), aplica al menos un filtro antes de consultar.",
  "schema": "ca",
  "view_name": "VW_Portfolio_ExtractoCartera",
  "heavy_view": true,
  "columns": [
    {"name": "Nit", "type": "varchar"},
    {"name": "RazonSocial", "type": "varchar"},
    {"name": "Fecha", "type": "date"},
    {"name": "FechaFactura", "type": "datetime2"},
    {"name": "TipoDocumento", "type": "varchar"},
    {"name": "Documento", "type": "varchar"},
    {"name": "Sucursal", "type": "varchar"},
    {"name": "Valor", "type": "decimal"},
    {"name": "Saldo", "type": "decimal"},
    {"name": "Dias", "type": "int"},
    {"name": "Rango", "type": "varchar"},
    {"name": "Estado", "type": "varchar"},
    {"name": "Sede", "type": "varchar"},
    {"name": "Contrato", "type": "varchar"}
  ],
  "suggestions": ["FechaFactura", "Fecha", "Nit", "TipoDocumento"],
  "hint": "Aplica al menos un filtro (fecha, Nit, sede, etc.) para consultar esta vista."
}
```

### Campos de la respuesta 422:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `error` | string | Siempre `"filters_required"` — para identificar este caso |
| `message` | string | Mensaje legible para el usuario |
| `schema` | string | Esquema de la vista |
| `view_name` | string | Nombre de la vista |
| `heavy_view` | bool | Siempre `true` en este caso |
| `columns` | array | TODAS las columnas de la vista con su tipo de dato |
| `suggestions` | array | Columnas recomendadas para filtrar (fechas, Nit, códigos) |
| `hint` | string | Texto corto de ayuda |

### Tipos de dato en `columns`:

| Tipo Fabric | Input recomendado en Angular |
|-------------|------------------------------|
| `date`, `datetime`, `datetime2`, `smalldatetime` | Datepicker (rango fecha inicio-fin) |
| `varchar`, `nvarchar` | Input text o autocomplete |
| `int`, `bigint`, `decimal`, `numeric` | Input numérico |
| `bit` | Checkbox o select (Sí/No) |

---

## Implementación en Laravel (Backend Gateway)

### GraphFabricService.php

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class GraphFabricService
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = config('services.graph_fabric.url'); // http://127.0.0.1:8001
        $this->token = config('services.graph_fabric.token'); // TOKEN_ADMIN
    }

    /**
     * Consulta dinámica a Graph-Fabric.
     * Retorna datos o información de filtros requeridos.
     */
    public function queryDynamic(array $params, object $user): array
    {
        $body = [
            'token'       => $this->token,
            'user_email'  => $user->email,
            'user_name'   => $user->name,
            'department'  => $user->department,
            'groups'      => $user->azure_groups,  // ["GG-BD-CA", "GG-BD-CO"]
            'schema_name' => $params['schema_name'],
            'view'        => $params['view'],
            'columns'     => $params['columns'] ?? [],
            'filters'     => $params['filters'] ?? [],
            'limit'       => $params['limit'] ?? 1000,
            'offset'      => $params['offset'] ?? 0,
            'sort_col'    => $params['sort_col'] ?? '',
            'sort_dir'    => $params['sort_dir'] ?? 'asc',
            'skip_count'  => $params['skip_count'] ?? false,
        ];

        $response = Http::timeout(125)
            ->post("{$this->baseUrl}/api/data/dynamic", $body);

        // Caso: Vista pesada sin filtros
        if ($response->status() === 422) {
            $data = $response->json();
            if (($data['error'] ?? '') === 'filters_required') {
                return [
                    'status'      => 'filters_required',
                    'message'     => $data['message'],
                    'columns'     => $data['columns'],
                    'suggestions' => $data['suggestions'],
                    'hint'        => $data['hint'],
                    'heavy_view'  => true,
                ];
            }
        }

        // Caso: Error de permisos
        if ($response->status() === 403) {
            return [
                'status'  => 'forbidden',
                'message' => $response->json()['detail'] ?? 'Sin permisos.',
            ];
        }

        // Caso: Timeout o error del servidor
        if ($response->failed()) {
            return [
                'status'  => 'error',
                'message' => 'Error consultando Graph-Fabric: ' . $response->status(),
            ];
        }

        // Caso: Éxito
        $data = $response->json();
        return [
            'status'     => 'ok',
            'items'      => $data['items'],
            'page_info'  => $data['page_info'],
            'heavy_view' => $data['page_info']['heavy_view'] ?? false,
        ];
    }
}
```

### FabricDataController.php

```php
<?php

namespace App\Http\Controllers;

use App\Services\GraphFabricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FabricDataController extends Controller
{
    public function __construct(
        private GraphFabricService $fabricService
    ) {}

    /**
     * POST /api/fabric/query
     * Angular llama este endpoint para consultar vistas.
     */
    public function query(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string',
            'view'        => 'required|string',
            'filters'     => 'nullable|array',
            'columns'     => 'nullable|array',
            'limit'       => 'nullable|integer|min:1|max:5000',
            'offset'      => 'nullable|integer|min:0',
            'sort_col'    => 'nullable|string',
            'sort_dir'    => 'nullable|in:asc,desc',
        ]);

        $result = $this->fabricService->queryDynamic(
            $request->only(['schema_name', 'view', 'filters', 'columns',
                           'limit', 'offset', 'sort_col', 'sort_dir']),
            $request->user()
        );

        // Respuesta según el status
        return match ($result['status']) {
            'filters_required' => response()->json([
                'requires_filters' => true,
                'message'          => $result['message'],
                'columns'          => $result['columns'],
                'suggestions'      => $result['suggestions'],
                'hint'             => $result['hint'],
                'heavy_view'       => true,
            ], 422),

            'forbidden' => response()->json([
                'error'   => 'forbidden',
                'message' => $result['message'],
            ], 403),

            'error' => response()->json([
                'error'   => 'server_error',
                'message' => $result['message'],
            ], 502),

            default => response()->json([
                'items'      => $result['items'],
                'page_info'  => $result['page_info'],
                'heavy_view' => $result['heavy_view'],
            ]),
        };
    }
}
```

---

## Implementación en Angular (Frontend)

### fabric-data.service.ts

```typescript
import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';

export interface QueryParams {
  schema_name: string;
  view: string;
  filters?: Record<string, any>;
  columns?: string[];
  limit?: number;
  offset?: number;
  sort_col?: string;
  sort_dir?: 'asc' | 'desc';
}

export interface Column {
  name: string;
  type: string;
}

export interface FiltersRequiredResponse {
  requires_filters: true;
  message: string;
  columns: Column[];
  suggestions: string[];
  hint: string;
  heavy_view: true;
}

export interface QueryResponse {
  items: any[];
  page_info: {
    total: number;
    limit: number;
    offset: number;
    has_next: boolean;
    sort_col: string;
    sort_dir: string;
    elapsed_ms: number;
    heavy_view: boolean;
  };
  heavy_view: boolean;
}

@Injectable({ providedIn: 'root' })
export class FabricDataService {
  private apiUrl = '/api/fabric';

  constructor(private http: HttpClient) {}

  query(params: QueryParams): Observable<QueryResponse | FiltersRequiredResponse> {
    return this.http.post<QueryResponse>(`${this.apiUrl}/query`, params).pipe(
      catchError((error: HttpErrorResponse) => {
        if (error.status === 422 && error.error?.requires_filters) {
          // No es un "error" real — es una señal de que se necesitan filtros
          return throwError(() => ({
            type: 'filters_required',
            data: error.error as FiltersRequiredResponse
          }));
        }
        return throwError(() => error);
      })
    );
  }
}
```

### data-table.component.ts

```typescript
import { Component, OnInit } from '@angular/core';
import { FabricDataService, QueryParams, Column, FiltersRequiredResponse } from './fabric-data.service';

@Component({
  selector: 'app-data-table',
  templateUrl: './data-table.component.html'
})
export class DataTableComponent implements OnInit {
  // Estado del componente
  items: any[] = [];
  loading = false;
  filtersRequired = false;
  heavyView = false;

  // Filtros obligatorios
  suggestedFilters: string[] = [];
  availableColumns: Column[] = [];
  filterMessage = '';
  filterHint = '';

  // Filtros del usuario
  activeFilters: Record<string, any> = {};

  // Paginación
  pageInfo = { total: 0, limit: 50, offset: 0, has_next: false };

  // Vista actual
  currentSchema = '';
  currentView = '';

  constructor(private fabricService: FabricDataService) {}

  ngOnInit() {}

  /**
   * Cargar datos de una vista.
   * Si requiere filtros, muestra el panel en vez de los datos.
   */
  loadView(schema: string, view: string) {
    this.currentSchema = schema;
    this.currentView = view;
    this.loading = true;
    this.filtersRequired = false;

    const params: QueryParams = {
      schema_name: schema,
      view: view,
      filters: this.activeFilters,
      limit: this.pageInfo.limit,
      offset: this.pageInfo.offset,
      sort_col: '',
      sort_dir: 'asc'
    };

    this.fabricService.query(params).subscribe({
      next: (response: any) => {
        this.loading = false;
        this.items = response.items;
        this.pageInfo = response.page_info;
        this.heavyView = response.heavy_view || false;
      },
      error: (err) => {
        this.loading = false;

        if (err.type === 'filters_required') {
          // Mostrar panel de filtros obligatorios
          const data: FiltersRequiredResponse = err.data;
          this.filtersRequired = true;
          this.suggestedFilters = data.suggestions;
          this.availableColumns = data.columns;
          this.filterMessage = data.message;
          this.filterHint = data.hint;
        } else {
          // Error real
          console.error('Error consultando vista:', err);
        }
      }
    });
  }

  /**
   * Aplicar filtros y recargar.
   * Se llama cuando el usuario llena los filtros obligatorios.
   */
  applyFilters() {
    // Limpiar filtros vacíos
    const cleanFilters: Record<string, any> = {};
    for (const [key, value] of Object.entries(this.activeFilters)) {
      if (value !== null && value !== '') {
        cleanFilters[key] = value;
      }
    }

    if (Object.keys(cleanFilters).length === 0) {
      // Aún no hay filtros — mostrar advertencia
      alert('Debes aplicar al menos un filtro para consultar esta vista.');
      return;
    }

    this.activeFilters = cleanFilters;
    this.pageInfo.offset = 0; // Reset a página 1
    this.loadView(this.currentSchema, this.currentView);
  }

  /**
   * Determinar el tipo de input para un filtro según el tipo de dato.
   */
  getInputType(column: Column): string {
    switch (column.type) {
      case 'date':
      case 'datetime':
      case 'datetime2':
      case 'smalldatetime':
        return 'date';
      case 'int':
      case 'bigint':
      case 'decimal':
      case 'numeric':
      case 'float':
        return 'number';
      case 'bit':
        return 'checkbox';
      default:
        return 'text';
    }
  }
}
```

### data-table.component.html

```html
<!-- Panel de filtros obligatorios -->
<div *ngIf="filtersRequired" class="filters-required-panel">
  <div class="alert alert-warning">
    <h4>⚠️ Filtros requeridos</h4>
    <p>{{ filterMessage }}</p>
    <small>{{ filterHint }}</small>
  </div>

  <div class="filter-form">
    <h5>Filtrar por:</h5>

    <!-- Filtros sugeridos (los más relevantes) -->
    <div *ngFor="let colName of suggestedFilters" class="filter-field">
      <label>{{ colName }}</label>
      <ng-container [ngSwitch]="getInputType(getColumn(colName))">
        <input *ngSwitchCase="'date'"
               type="date"
               [(ngModel)]="activeFilters[colName]"
               class="form-control" />
        <input *ngSwitchCase="'number'"
               type="number"
               [(ngModel)]="activeFilters[colName]"
               class="form-control"
               placeholder="Valor numérico..." />
        <input *ngSwitchDefault
               type="text"
               [(ngModel)]="activeFilters[colName]"
               class="form-control"
               placeholder="Buscar {{ colName }}..." />
      </ng-container>
    </div>

    <button (click)="applyFilters()" class="btn btn-primary mt-3">
      Consultar con filtros
    </button>
  </div>

  <!-- Todas las columnas disponibles (expandible) -->
  <details class="mt-3">
    <summary>Ver todas las columnas disponibles ({{ availableColumns.length }})</summary>
    <table class="table table-sm mt-2">
      <thead>
        <tr><th>Columna</th><th>Tipo</th></tr>
      </thead>
      <tbody>
        <tr *ngFor="let col of availableColumns">
          <td>{{ col.name }}</td>
          <td><code>{{ col.type }}</code></td>
        </tr>
      </tbody>
    </table>
  </details>
</div>

<!-- Loading -->
<div *ngIf="loading" class="text-center p-4">
  <div class="spinner-border"></div>
  <p>Consultando datos...</p>
</div>

<!-- Tabla de datos (solo cuando hay items) -->
<div *ngIf="!loading && !filtersRequired && items.length > 0">
  <!-- Indicador de vista pesada -->
  <div *ngIf="heavyView" class="badge bg-warning mb-2">
    ⚡ Vista pesada (millones de registros) — Los filtros mejoran la velocidad
  </div>

  <!-- Tabla normal aquí -->
  <table class="table">
    <!-- ... tu tabla existente ... -->
  </table>

  <!-- Paginación -->
  <div class="pagination-info">
    <span *ngIf="pageInfo.total === -1">Muchos registros</span>
    <span *ngIf="pageInfo.total !== -1">{{ pageInfo.total | number }} registros</span>
    <span> | {{ pageInfo.elapsed_ms }}ms</span>
  </div>
</div>
```

---

## Cómo funciona internamente Graph-Fabric

### Paso 1: Conteo de filas (Redis cache)

```python
# En src/db/repository.py → get_row_count_fast()
def get_row_count_fast(self, schema, view_name, database):
    # 1. Buscar en Redis (instantáneo)
    cached = cache.get(f"gf:rowcount:{schema}.{view_name}")
    if cached is not None:
        return cached  # ← 0ms, devuelve el número cacheado

    # 2. Si no hay cache → ejecutar COUNT(*) sincrónico
    #    (tarda 60-90s la primera vez, luego queda en cache 30 min)
    count = self.get_row_count(schema, view_name, database)
    return count  # ← Ej: 7,482,735
```

### Paso 2: Validación en views_service.py

```python
# En src/services/views_service.py → query_dynamic()

# Obtener conteo (de Redis o sincrónico)
row_estimate = self._repo.get_row_count_fast(schema, view_name)

# ¿Supera el umbral?
heavy = row_estimate >= HEAVY_THRESHOLD  # 1,000,000

if heavy and not active_filters:
    # Obtener columnas para sugerir filtros
    cols = self._repo.discover_view_columns(schema, view_name)
    raise FiltersRequiredError(
        message="La vista contiene mas de 1,000,000 registros...",
        columns=cols,
        suggestions=["FechaFactura", "Nit", ...]
    )
```

### Paso 3: Respuesta en el router

```python
# En src/api/routers/dynamic.py → dynamic_query()
except FiltersRequiredError as exc:
    return Response(
        content=json({
            "error": "filters_required",
            "columns": exc.columns,
            "suggestions": exc.suggestions,
            ...
        }),
        status_code=422
    )
```

---

## Configuración (.env en VPS)

```env
# Umbral para exigir filtros (número de filas)
HEAVY_THRESHOLD=1000000

# Cache del conteo de filas (30 min)
REDIS_TTL_ROW_COUNT=1800

# Cache extendido para queries en vistas pesadas (10 min)
REDIS_TTL_HEAVY=600

# Cache para queries normales (5 min)
REDIS_TTL_QUERY=300

# Timeout por query a Fabric (antes 30s, ahora 120s)
FABRIC_COMMAND_TIMEOUT=120
```

---

## Tiempos de respuesta esperados

| Escenario | Tiempo |
|-----------|--------|
| Vista normal (<1M) sin filtros | 2-8 seg |
| Vista normal, 2da consulta (Redis) | <10 ms |
| Vista pesada, 1ra vez sin cache conteo | 60-90 seg (COUNT) + 422 |
| Vista pesada, 2da vez sin filtros | **<50 ms** (422 inmediato desde Redis) |
| Vista pesada CON filtro (1ra vez) | 5-60 seg (depende del filtro) |
| Vista pesada CON filtro (2da vez, Redis) | **<10 ms** |

---

## Preguntas frecuentes

### ¿Qué pasa la primera vez que alguien consulta una vista pesada?

La primera vez (sin cache de conteo en Redis), Graph-Fabric ejecuta `COUNT(*)` sincrónico. Esto puede tardar 60-90 segundos. Luego cachea el resultado 30 minutos. Las siguientes consultas son instantáneas.

**Recomendación:** Usar `/api/cache/warm` con cron para pre-calentar los conteos de las vistas financieras al inicio del día.

### ¿Qué cuenta como "filtro activo"?

Solo filtros con valor no vacío. Estos NO cuentan:
- `{"Nit": ""}` → vacío, no cuenta
- `{"Nit": null}` → null, no cuenta
- `{"Nit": "   "}` → espacios, no cuenta (se trima)

Estos SÍ cuentan:
- `{"Nit": "900156264"}` → valor real
- `{"Fecha": "2026-01-01"}` → fecha específica
- `{"Sucursal": "%Neiva%"}` → búsqueda LIKE

### ¿Puedo cambiar el umbral sin reiniciar?

No directamente. El umbral se lee de `.env` al arrancar. Si cambias `HEAVY_THRESHOLD=500000` necesitas `systemctl restart graph-fabric`.

### ¿Cómo limpiar el cache de conteo si los datos cambiaron?

```bash
# Limpiar todos los conteos (fuerza re-conteo en la siguiente consulta)
redis-cli KEYS "gf:rowcount:*" | xargs redis-cli DEL

# O limpiar todo el cache
curl -X DELETE "http://127.0.0.1:8001/api/cache/clear?token=TU_TOKEN_ADMIN"
```

### ¿Qué pasa si Redis se cae?

Sin Redis, `get_row_count_fast()` ejecuta el COUNT sincrónico cada vez. Las vistas pesadas tardarán 60-90s extra. Redis es importante para la experiencia del usuario.

---

## Endpoint de pre-calentamiento (Opcional)

Para evitar que el primer usuario del día espere 90s:

```bash
# Cron en VPS: cada 30 min, pre-calcular conteos de esquemas financieros
*/30 * * * * curl -s -X POST http://127.0.0.1:8001/api/cache/warm \
  -H "Content-Type: application/json" \
  -d '{"token":"TOKEN_ADMIN_AQUI","user_email":"system","user_name":"System","department":"MA-TIC NAL","groups":["GG-BD-CA","GG-BD-DF","GG-BD-FR"]}' \
  > /dev/null 2>&1
```

Este endpoint recorre las vistas de los esquemas `ca`, `df`, `fr` y ejecuta el conteo rápido para cada una. Así el Redis ya tiene los datos cuando el usuario llega.

---

*Última actualización: 2026-07-07*


---

## 📝 Notas del equipo GraphSQL — Resumen de cambios aplicados

El problema principal era doble:

1. **CommandTimeout=30** — cortaba las queries de cartera antes de que Fabric respondiera (cartera tarda 60-90s). Eso causaba el error que veías en producción.
2. **Redis TTL de 1 minuto** — el cache expiraba muy rápido, entonces casi siempre se iba a Fabric de nuevo (hit rate 33%). Ahora con 5 min el hit rate sube significativamente.

### Flujo actual con los cambios aplicados:

1. Primera vez que alguien toca una vista → Graph-Fabric hace `COUNT(*)` (tarda 60-90s en cartera), guarda en Redis 30 min
2. Si supera 1M filas y no hay filtros → devuelve HTTP 422 con las columnas para filtrar
3. Si hay filtros → ejecuta normal

### Lo que se debe ajustar en Laravel (3 puntos):

| # | Qué hacer | Por qué | Estado |
|---|-----------|---------|--------|
| 1 | Detectar 422 + `error: "filters_required"` y devolver a Angular | Para que muestre el formulario de filtros | ✅ Implementado |
| 2 | Subir `Http::timeout(125)` | Porque el timeout de Fabric ahora es 120s | ✅ Implementado |
| 3 | Pasar `heavy_view` y manejar `total: -1` al frontend | Para que Angular muestre indicadores correctos | ✅ Implementado |

### Código de referencia (ya aplicado en `GraphFabricGatewayService::post()`):

```php
$response = Http::timeout(125)->post("{$this->graphFabricUrl}/api/data/dynamic", $body);

if ($response->status() === 422) {
    $data = $response->json();
    if (($data['error'] ?? '') === 'filters_required') {
        return [
            '__filters_required' => true,
            'message'     => $data['message'],
            'suggestions' => $data['suggestions'],
            'columns'     => $data['columns'],
            'heavy_view'  => true,
        ];
    }
}
```

### Sobre el error de "Conversion failed" (ODBC 22007):

El error `503 "Conversion failed when converting date and/or time from character string"` **NO es de Redis, ni de Laravel, ni de Graph-Fabric**. Es de SQL Server (Fabric Lakehouse). Significa que:

- Un filtro de fecha llegó en formato incorrecto (ej: `06/07/2026` en vez de `2026-07-06`), **O**
- Los datos en la vista tienen strings inválidos en columnas de tipo fecha

**Solución aplicada en Laravel:** `normalizeFilters()` ahora convierte automáticamente:
- `dd/mm/yyyy` → `yyyy-mm-dd`
- `d/m/yyyy` → `yyyy-mm-dd`
- `dd-mm-yyyy` → `yyyy-mm-dd`
- `dd/mm/yyyy HH:mm` → `yyyy-mm-dd HH:mm:ss`
- Rangos `{from, to}` se normalizan individualmente

Si el error persiste con filtros ya en ISO o sin filtros, es problema de la definición de la vista en el Lakehouse (otro equipo).

### Sobre Redis y datos nuevos (ej: Julio 2026):

Redis NO bloquea datos nuevos — el TTL es de 1 minuto para queries normales y 10 minutos para vistas pesadas. Después de ese tiempo, siempre consulta datos frescos de Fabric.

Para forzar refresh:
```bash
# Limpiar caché desde la VPS
redis-cli FLUSHDB

# O desde la API
curl -X DELETE "http://127.0.0.1:8001/api/cache/clear?token=TOKEN_ADMIN"
```

---

*Sección agregada: 7 de Julio de 2026*
