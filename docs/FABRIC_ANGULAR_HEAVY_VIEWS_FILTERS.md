# 🔔 Fabric Viewer — Manejo de Vistas Pesadas desde Notificaciones (Angular)

**Fecha:** 7 de Julio de 2026  
**Contexto:** Cuando un usuario hace clic en una notificación de interconsulta, el frontend navega al Fabric Viewer con la vista `VW_HC_NotificacionesInterconsultas`. Si esa vista supera 1M de registros, el backend responde HTTP 422 pidiendo filtros. Este documento explica cómo manejar ese flujo.

---

## Problema

1. Usuario recibe notificación de interconsulta (email o badge en la app)
2. Hace clic → Angular navega al Fabric Viewer con `schema=ex`, `view=VW_HC_NotificacionesInterconsultas`
3. Si la vista tiene >1M registros y no se envían filtros → **HTTP 422 `filters_required`**
4. El usuario ve un panel de "filtros requeridos" en vez de sus datos

**Solución:** Cuando se navega desde una notificación, pre-aplicar filtros automáticamente (fecha, ingreso, paciente) para que la consulta pase directo sin pedirle al usuario que filtre manualmente.

---

## Flujo propuesto

```
Notificación (clic) 
  → Router Angular con queryParams: { schema, view, filtros pre-aplicados }
    → Componente Fabric Viewer
      → Detecta queryParams con filtros
        → Envía request CON filtros (no vacío)
          → Backend pasa a Python → 200 OK con datos
            → Muestra tabla con los datos filtrados
```

---

## Implementación paso a paso

### 1. Ruta con queryParams desde la notificación

```typescript
// notification.component.ts — Al hacer clic en una notificación
onNotificationClick(notification: Notification) {
  // Navegar al viewer con filtros pre-aplicados
  this.router.navigate(['/fabric/viewer'], {
    queryParams: {
      schema: 'ex',
      view: 'VW_HC_NotificacionesInterconsultas',
      // Filtros que garantizan que no sea heavy (al menos uno)
      'filter_Ingreso': notification.ingreso,
      'filter_Fecha_Orden': notification.fechaOrden, // yyyy-mm-dd
      'filter_Paciente': notification.paciente,
    }
  });
}
```

### 2. Componente Viewer — Leer filtros de queryParams al iniciar

```typescript
// fabric-viewer.component.ts
import { ActivatedRoute } from '@angular/router';

export class FabricViewerComponent implements OnInit {
  // Estado
  currentSchema = '';
  currentView = '';
  activeFilters: Record<string, any> = {};
  filtersRequired = false;
  suggestedFilters: string[] = [];
  filterColumns: Column[] = [];
  items: any[] = [];
  loading = false;
  heavyView = false;
  pageInfo: PageInfo = { total: 0, limit: 50, offset: 0, has_next: false };

  constructor(
    private route: ActivatedRoute,
    private fabricService: FabricDataService
  ) {}

  ngOnInit() {
    this.route.queryParams.subscribe(params => {
      if (params['schema'] && params['view']) {
        this.currentSchema = params['schema'];
        this.currentView = params['view'];

        // Extraer filtros pre-aplicados del queryParam (prefijo "filter_")
        this.activeFilters = this.extractFiltersFromParams(params);

        // Cargar datos automáticamente
        this.loadData();
      }
    });
  }

  /**
   * Extrae filtros de los queryParams.
   * Convención: queryParam "filter_NombreColumna" = valor del filtro
   */
  private extractFiltersFromParams(params: Record<string, string>): Record<string, any> {
    const filters: Record<string, any> = {};
    for (const [key, value] of Object.entries(params)) {
      if (key.startsWith('filter_') && value) {
        const colName = key.replace('filter_', '');
        filters[colName] = value;
      }
    }
    return filters;
  }

  /**
   * Carga datos. Si recibe 422, muestra panel de filtros.
   * Si ya tiene filtros pre-aplicados (desde notificación), pasan directo.
   */
  loadData() {
    this.loading = true;
    this.filtersRequired = false;

    this.fabricService.query({
      schema_name: this.currentSchema,
      view: this.currentView,
      filters: this.activeFilters,
      limit: this.pageInfo.limit,
      offset: this.pageInfo.offset,
      sort_col: '',
      sort_dir: 'desc',
    }).subscribe({
      next: (res: any) => {
        this.loading = false;
        this.items = res.data ?? res.items ?? [];
        this.pageInfo = res.meta ?? res.page_info ?? this.pageInfo;
        this.heavyView = res.meta?.heavy_view || res.heavy_view || false;
      },
      error: (err) => {
        this.loading = false;
        this.handleQueryError(err);
      }
    });
  }

  /**
   * Maneja el error 422 (filters_required) y otros errores.
   */
  private handleQueryError(err: any) {
    // Error de tipo filters_required (desde el catchError del servicio)
    if (err.status === 422 && err.error?.requires_filters) {
      this.filtersRequired = true;
      this.suggestedFilters = err.error.suggestions ?? [];
      this.filterColumns = err.error.columns ?? [];
      this.filterMessage = err.error.message ?? 'Esta vista requiere filtros.';
      return;
    }

    // Si viene como objeto custom del servicio
    if (err.type === 'filters_required') {
      this.filtersRequired = true;
      this.suggestedFilters = err.data.suggestions ?? [];
      this.filterColumns = err.data.columns ?? [];
      this.filterMessage = err.data.message ?? 'Esta vista requiere filtros.';
      return;
    }

    // Otros errores
    console.error('Error en Fabric Viewer:', err);
    this.snackBar.open('Error consultando datos. Intente de nuevo.', 'OK', { duration: 5000 });
  }

  /**
   * Usuario aplica filtros manualmente desde el panel.
   */
  applyManualFilters() {
    // Limpiar filtros vacíos
    const clean: Record<string, any> = {};
    for (const [key, value] of Object.entries(this.activeFilters)) {
      if (value !== null && value !== '' && String(value).trim() !== '') {
        clean[key] = value;
      }
    }

    if (Object.keys(clean).length === 0) {
      this.snackBar.open('Aplica al menos un filtro para consultar esta vista.', 'OK', { duration: 4000 });
      return;
    }

    this.activeFilters = clean;
    this.pageInfo.offset = 0;
    this.loadData();
  }
}
```

---

### 3. Template — Panel de filtros requeridos con UX clara

```html
<!-- fabric-viewer.component.html -->

<!-- ═══════════════════════════════════════════════════════ -->
<!-- CASO 1: Vista pesada SIN filtros → Mostrar panel       -->
<!-- ═══════════════════════════════════════════════════════ -->
@if (filtersRequired) {
  <div class="card border-warning mb-3">
    <div class="card-header bg-warning text-dark">
      <i class="bi bi-funnel-fill"></i>
      <strong> Filtros requeridos</strong>
    </div>
    <div class="card-body">
      <p class="card-text">{{ filterMessage }}</p>

      <!-- Filtros sugeridos (los más relevantes para esta vista) -->
      <div class="row g-3">
        @for (colName of suggestedFilters; track colName) {
          <div class="col-md-4 col-sm-6">
            <label class="form-label fw-bold">{{ colName | translateColumn }}</label>

            @if (isDateColumn(colName)) {
              <!-- Filtro de fecha: rango inicio-fin -->
              <div class="input-group input-group-sm">
                <input type="date"
                       class="form-control"
                       [placeholder]="'Desde'"
                       (change)="onFilterDate(colName, $event.target.value)">
              </div>
            } @else if (isNumberColumn(colName)) {
              <input type="number"
                     class="form-control form-control-sm"
                     [placeholder]="'Valor de ' + colName"
                     (input)="activeFilters[colName] = $event.target.value">
            } @else {
              <input type="text"
                     class="form-control form-control-sm"
                     [placeholder]="'Buscar ' + (colName | translateColumn) + '...'"
                     (input)="activeFilters[colName] = $event.target.value">
            }
          </div>
        }
      </div>

      <!-- Botón consultar -->
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary" (click)="applyManualFilters()">
          <i class="bi bi-search"></i> Consultar con filtros
        </button>
        <button class="btn btn-outline-secondary" (click)="showAllColumns = !showAllColumns">
          <i class="bi bi-list-columns"></i> Ver todas las columnas
        </button>
      </div>

      <!-- Tabla expandible con todas las columnas disponibles -->
      @if (showAllColumns) {
        <div class="mt-3">
          <table class="table table-sm table-striped">
            <thead>
              <tr>
                <th>Columna</th>
                <th>Tipo</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              @for (col of filterColumns; track col.name) {
                <tr>
                  <td>{{ col.name | translateColumn }}</td>
                  <td><code>{{ col.type }}</code></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary"
                            (click)="addFilterField(col.name)">
                      + Filtrar
                    </button>
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    </div>
  </div>
}

<!-- ═══════════════════════════════════════════════════════ -->
<!-- CASO 2: Cargando datos                                 -->
<!-- ═══════════════════════════════════════════════════════ -->
@if (loading) {
  <div class="text-center py-5">
    <div class="spinner-border text-primary" role="status"></div>
    <p class="mt-2 text-muted">Consultando datos de Fabric...</p>
    @if (heavyView) {
      <small class="text-warning">Vista pesada — puede tardar hasta 60 segundos</small>
    }
  </div>
}

<!-- ═══════════════════════════════════════════════════════ -->
<!-- CASO 3: Datos cargados exitosamente                    -->
<!-- ═══════════════════════════════════════════════════════ -->
@if (!loading && !filtersRequired && items.length > 0) {
  <!-- Badge indicador de vista pesada -->
  @if (heavyView) {
    <div class="alert alert-info py-1 px-3 d-inline-block mb-2">
      <i class="bi bi-lightning-fill"></i>
      Vista con alto volumen — filtros activos: {{ getActiveFilterCount() }}
    </div>
  }

  <!-- Filtros activos (chips removibles) -->
  @if (getActiveFilterCount() > 0) {
    <div class="mb-2 d-flex flex-wrap gap-1">
      @for (entry of getActiveFilterEntries(); track entry.key) {
        <span class="badge bg-primary d-flex align-items-center gap-1">
          {{ entry.key | translateColumn }}: {{ entry.value }}
          <button class="btn-close btn-close-white btn-sm"
                  (click)="removeFilter(entry.key)"></button>
        </span>
      }
      <button class="btn btn-sm btn-outline-danger" (click)="clearAllFilters()">
        Limpiar filtros
      </button>
    </div>
  }

  <!-- Tu tabla de datos existente -->
  <!-- ... -->
}
```

---

### 4. Helpers del componente

```typescript
// Helpers para el template

/** Determina si una columna es de tipo fecha */
isDateColumn(colName: string): boolean {
  const col = this.filterColumns.find(c => c.name === colName);
  if (!col) return colName.toLowerCase().includes('fecha');
  return ['date', 'datetime', 'datetime2', 'smalldatetime'].includes(col.type);
}

/** Determina si una columna es numérica */
isNumberColumn(colName: string): boolean {
  const col = this.filterColumns.find(c => c.name === colName);
  if (!col) return false;
  return ['int', 'bigint', 'decimal', 'numeric', 'float', 'money'].includes(col.type);
}

/** Handler para filtros de fecha — convierte a ISO automáticamente */
onFilterDate(colName: string, value: string) {
  // El input type="date" ya devuelve yyyy-mm-dd (ISO)
  this.activeFilters[colName] = value;
}

/** Agrega un campo de filtro extra (desde "Ver todas las columnas") */
addFilterField(colName: string) {
  if (!this.suggestedFilters.includes(colName)) {
    this.suggestedFilters = [...this.suggestedFilters, colName];
  }
}

/** Cuenta filtros activos */
getActiveFilterCount(): number {
  return Object.values(this.activeFilters).filter(v => v !== null && v !== '').length;
}

/** Entries de filtros activos para mostrar como chips */
getActiveFilterEntries(): { key: string; value: string }[] {
  return Object.entries(this.activeFilters)
    .filter(([_, v]) => v !== null && v !== '')
    .map(([key, value]) => ({ key, value: String(value) }));
}

/** Quitar un filtro específico */
removeFilter(colName: string) {
  delete this.activeFilters[colName];
  this.loadData();
}

/** Limpiar todos los filtros — volverá a mostrar el panel 422 si es heavy */
clearAllFilters() {
  this.activeFilters = {};
  this.loadData();
}
```

---

### 5. Servicio HTTP — Manejo del 422

```typescript
// fabric-data.service.ts
import { Injectable } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from '@env/environment';

export interface QueryParams {
  schema_name: string;
  view: string;
  filters?: Record<string, any>;
  columns?: string[];
  limit?: number;
  offset?: number;
  sort_col?: string;
  sort_dir?: 'asc' | 'desc';
  skip_count?: boolean;
}

export interface Column {
  name: string;
  type: string;
}

export interface PageInfo {
  total: number;       // -1 = no calculado (skip_count activo)
  limit: number;
  offset: number;
  has_next: boolean;
  heavy_view?: boolean;
  elapsed_ms?: number;
}

@Injectable({ providedIn: 'root' })
export class FabricDataService {
  private baseUrl = `${environment.apiUrl}/fabric/viewer`;

  constructor(private http: HttpClient) {}

  /**
   * POST /api/fabric/viewer/data
   * Maneja 422 como caso especial (no error fatal).
   */
  queryData(params: QueryParams): Observable<any> {
    return this.http.post(`${this.baseUrl}/data`, params).pipe(
      catchError((error: HttpErrorResponse) => {
        // Propagar el error tal cual para que el componente lo maneje
        return throwError(() => error);
      })
    );
  }
}
```

---

### 6. Navegación desde notificación con filtros pre-aplicados

```typescript
// Ejemplo: componente de lista de notificaciones
// Cuando el usuario hace clic en una notificación de interconsulta

/**
 * Las notificaciones de interconsulta contienen datos del paciente.
 * Usamos esos datos como filtros para que la vista cargue directo.
 */
navigateToInterconsulta(notif: InterconsultaNotification) {
  const queryParams: any = {
    schema: 'ex',
    view: 'VW_HC_NotificacionesInterconsultas',
  };

  // Pre-aplicar filtros según los datos disponibles en la notificación
  if (notif.ingreso) {
    queryParams['filter_Ingreso'] = notif.ingreso;
  }
  if (notif.fechaOrden) {
    // Asegurar formato ISO (yyyy-mm-dd)
    queryParams['filter_Fecha_Orden'] = this.toIso(notif.fechaOrden);
  }
  if (notif.identificacion) {
    queryParams['filter_Identificacion'] = notif.identificacion;
  }

  this.router.navigate(['/fabric/viewer'], { queryParams });
}

/**
 * Convierte fecha a ISO si viene en otro formato.
 * El input type="date" de HTML siempre devuelve yyyy-mm-dd.
 */
private toIso(date: string | Date): string {
  if (date instanceof Date) {
    return date.toISOString().split('T')[0];
  }
  // Si ya es ISO, devolver tal cual
  if (/^\d{4}-\d{2}-\d{2}/.test(date)) {
    return date.substring(0, 10);
  }
  // dd/mm/yyyy → yyyy-mm-dd
  const parts = date.split('/');
  if (parts.length === 3) {
    return `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
  }
  return date;
}
```

---

## Respuesta del Backend (lo que Angular recibe)

### Caso exitoso (200) — con filtros aplicados

```json
{
  "success": true,
  "data": [
    {
      "Ingreso": "12345",
      "Identificacion": "1098765432",
      "Paciente": "GARCIA LOPEZ MARIA",
      "Clinica": "Medilaser Neiva",
      "Fecha_Orden": "2026-07-06T14:30:00",
      "EstadoOrden": "SOLICITADO",
      "Especialidad_Ordenada": "CARDIOLOGIA"
    }
  ],
  "meta": {
    "total": 142,
    "limit": 50,
    "offset": 0,
    "has_next": true,
    "heavy_view": false,
    "elapsed_ms": 3200
  }
}
```

### Caso 422 — vista pesada sin filtros

```json
{
  "success": false,
  "requires_filters": true,
  "message": "La vista 'ex.VW_HC_NotificacionesInterconsultas' contiene mas de 1,000,000 registros...",
  "suggestions": ["Fecha_Orden", "Ingreso", "Identificacion", "EstadoOrden"],
  "columns": [
    {"name": "Ingreso", "type": "varchar"},
    {"name": "Identificacion", "type": "varchar"},
    {"name": "Paciente", "type": "varchar"},
    {"name": "Clinica", "type": "varchar"},
    {"name": "Fecha_Orden", "type": "datetime2"},
    {"name": "EstadoOrden", "type": "varchar"},
    {"name": "Especialidad_Ordenada", "type": "varchar"},
    {"name": "Profesional", "type": "varchar"},
    {"name": "Email", "type": "varchar"}
  ],
  "heavy_view": true,
  "schema": "ex",
  "view_name": "VW_HC_NotificacionesInterconsultas"
}
```

---

## Resumen visual del flujo

```
┌─────────────────────────────────────────────────────────────────┐
│                    DESDE NOTIFICACIÓN                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Clic en notificación                                        │
│     ↓                                                           │
│  2. router.navigate con queryParams:                            │
│     schema=ex, view=VW_HC_..., filter_Ingreso=12345             │
│     ↓                                                           │
│  3. Componente lee queryParams → extrae filtros                 │
│     ↓                                                           │
│  4. POST /api/fabric/viewer/data  { filters: {Ingreso:"12345"} }│
│     ↓                                                           │
│  5. Backend detecta filtros → pasa a Python → 200 OK            │
│     ↓                                                           │
│  6. Tabla cargada con datos del paciente ✅                     │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    NAVEGACIÓN DIRECTA (sin filtros)              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Usuario abre Fabric Viewer manualmente                      │
│     ↓                                                           │
│  2. Selecciona esquema "ex" y vista "VW_HC_Notificaciones..."   │
│     ↓                                                           │
│  3. POST /api/fabric/viewer/data  { filters: {} }               │
│     ↓                                                           │
│  4. Backend → Python → Redis dice >1M → HTTP 422                │
│     ↓                                                           │
│  5. Angular muestra panel de filtros con sugerencias            │
│     ↓                                                           │
│  6. Usuario llena Fecha_Orden = "2026-07-06", clic "Consultar"  │
│     ↓                                                           │
│  7. POST con filtros → 200 OK → Tabla con datos ✅              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Consideraciones importantes

### Fechas siempre en ISO

El backend normaliza fechas, pero es mejor enviarlas ya en ISO desde Angular:

```typescript
// ✅ CORRECTO — el input type="date" ya devuelve yyyy-mm-dd
<input type="date" (change)="activeFilters['Fecha_Orden'] = $event.target.value">

// ✅ CORRECTO — desde código
this.activeFilters['Fecha_Orden'] = '2026-07-06';

// ❌ EVITAR — formatos locales (aunque el backend los convierte)
this.activeFilters['Fecha_Orden'] = '06/07/2026';
```

### Filtros y valores originales (no traducidos)

Los filtros SIEMPRE usan el valor original de la BD:

```typescript
// ✅ CORRECTO — EstadoOrden usa el valor que tiene Fabric
this.activeFilters['EstadoOrden'] = 'SOLICITADO';

// ❌ INCORRECTO — el valor traducido no existe en la BD
this.activeFilters['EstadoOrden'] = 'Solicitado';
```

### Paginación con `total: -1`

Cuando la vista es pesada y se usa `skip_count`, el total viene como `-1`:

```typescript
// En el template
@if (pageInfo.total === -1) {
  <span class="text-muted">Muchos registros</span>
} @else {
  <span>{{ pageInfo.total | number }} registros</span>
}

// Paginación: usar has_next en vez de total
get canGoNext(): boolean {
  return this.pageInfo.has_next;
}
get canGoPrev(): boolean {
  return this.pageInfo.offset > 0;
}
```

### Limpiar filtros = vuelve al panel 422

Si el usuario limpia todos los filtros en una vista pesada, el backend vuelve a responder 422. Eso está bien — Angular vuelve a mostrar el panel de filtros sugeridos.

---

## Checklist de implementación Angular

| # | Tarea | Prioridad |
|---|-------|-----------|
| 1 | Leer `queryParams` con prefijo `filter_` al iniciar componente | Alta |
| 2 | Manejar HTTP 422 `requires_filters` → mostrar panel de filtros | Alta |
| 3 | Renderizar inputs según tipo de columna (date/text/number) | Alta |
| 4 | Botón "Consultar con filtros" → re-enviar request | Alta |
| 5 | Navegación desde notificación con filtros pre-aplicados | Alta |
| 6 | Chips removibles para filtros activos | Media |
| 7 | Badge "Vista pesada" cuando `heavy_view === true` | Media |
| 8 | Manejar `total: -1` en paginador (next/prev) | Media |
| 9 | Tabla expandible "Ver todas las columnas" | Baja |
| 10 | Convertir fechas a ISO en `toIso()` antes de enviar | Alta |

---

*Última actualización: 7 de Julio de 2026*
