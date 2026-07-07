# 📊 Fabric Viewer — Cambios Angular V2

**Fecha:** 6 de Julio de 2026  
**Cambios:** skip_count, vistas pesadas, rate limit, exports optimizados

---

## 🆕 Cambios en el servicio Angular

### 1. Nuevo campo `skip_count` en queryData

```typescript
// Para vistas pesadas o cuando limit > 1000
queryData(params: {
  schema_name: string;
  view: string;
  columns?: string[];
  filters?: Record<string, string>;
  limit?: number;
  offset?: number;
  sort_col?: string;
  sort_dir?: 'asc' | 'desc';
  skip_count?: boolean;  // ← NUEVO: no ejecutar COUNT (ahorra hasta 150 seg)
}): Observable<DataResponse>
```

**Cuándo usar `skip_count: true`:**
- Vistas con más de 1M de filas
- Cuando el usuario navega con "Siguiente/Anterior" en vez de ir a página específica
- El backend lo activa automáticamente si `limit > 1000`

### 2. Manejar `total: -1` en la respuesta

Cuando `skip_count` está activo, la API devuelve `total: -1`:

```typescript
// En el componente de tabla
interface PageInfo {
  total: number;      // -1 = no calculado (skip_count activo)
  limit: number;
  offset: number;
  has_next: boolean;  // ← usar esto para paginación
  sort_col?: string;
  sort_dir?: string;
  elapsed_ms?: number;
}

// Mostrar total
get totalLabel(): string {
  if (this.pageInfo().total === -1) {
    return 'Muchos registros';
  }
  return this.pageInfo().total.toLocaleString() + ' registros';
}

// Paginación cuando total = -1 (next/prev en vez de ir a página N)
get canGoNext(): boolean {
  return this.pageInfo().has_next;
}

get canGoPrev(): boolean {
  return this.pageInfo().offset > 0;
}

// Botón "Siguiente" en vez de paginador numérico cuando total=-1
get usarPaginacionInfinita(): boolean {
  return this.pageInfo().total === -1;
}
```

### 3. Detección DINÁMICA de vistas pesadas (ya NO lista estática)

**Cambio importante (7 Jul 2026):** La API Python ahora detecta automáticamente vistas con >1M filas.
Ya NO se usa una lista fija `heavyViews[]`. Cualquier vista que supere el umbral será bloqueada
automáticamente si no tiene filtros.

El backend retorna **HTTP 422** con la estructura `filters_required`:

```json
{
  "success": false,
  "requires_filters": true,
  "message": "La vista 'ca.VW_Portfolio_ExtractoCartera' contiene mas de 1,000,000 registros...",
  "suggestions": ["FechaFactura", "Fecha", "Nit", "TipoDocumento"],
  "columns": [
    { "name": "Nit", "type": "varchar" },
    { "name": "FechaFactura", "type": "datetime2" },
    { "name": "Sucursal", "type": "varchar" }
  ],
  "heavy_view": true,
  "schema": "ca",
  "view_name": "VW_Portfolio_ExtractoCartera"
}
```

**Manejar en Angular:**
```typescript
// En el componente
onViewSelected(schema: string, view: string) {
  this.fabricService.queryData({
    schema_name: schema,
    view: view,
    limit: 50,
    offset: 0,
  }).subscribe({
    next: (res) => {
      // Si page_info tiene heavy_view: true, mostrar indicador visual
      if (res.meta?.heavy_view) {
        this.isHeavyView.set(true);
      }
      this.rows.set(res.data);
      this.pageInfo.set(res.meta);
    },
    error: (err) => {
      if (err.status === 422 && err.error?.requires_filters) {
        // Mostrar panel de filtros obligatorios con sugerencias y tipos
        this.snackBar.open(err.error.message, 'OK', { duration: 8000 });
        this.suggestedFilters.set(err.error.suggestions);   // ["Nit", "FechaFactura"]
        this.filterColumns.set(err.error.columns);          // con type para inputs
        this.showFilterRequired.set(true);
      }
    }
  });
}
```

**Panel de filtros dinámico basado en `columns[]`:**
```typescript
// Renderizar inputs según el tipo de columna
@for (col of filterColumns(); track col.name) {
  <div class="filter-field">
    <label>{{ col.name | translateColumn }}</label>

    @switch (getColumnType(col.type)) {
      @case ('date') {
        <input type="date"
               (change)="onSuggestedFilter(col.name, $event.target.value)">
      }
      @case ('number') {
        <input type="number"
               (input)="onSuggestedFilter(col.name, $event.target.value)">
      }
      @default {
        <input type="text" placeholder="Filtrar por {{ col.name | translateColumn }}"
               (input)="onSuggestedFilter(col.name, $event.target.value)">
      }
    }
  </div>
}
```

**Notas importantes:**
- Ya no necesitas mantener una lista fija de vistas pesadas en el frontend
- La detección es automática: si una vista crece a >1M filas, se activa sola
- En la primera consulta a una vista nueva, pasa sin bloquear (el conteo se hace en background)
- Desde la 2ª consulta, si detectó >1M, bloquea con 422 y sugerencias

---

## 📐 Rate Limits actualizados

| Operación | Límite anterior | **Nuevo límite** |
|-----------|----------------|-----------------|
| `/viewer/data` | 30/min | **60/min** |
| `/viewer/export` | 3/min | **10/min** |
| `/viewer/columns` | 20/min | **30/min** |
| `/viewer/views` | 10/min | **30/min** |

---

## 📥 Export — Cambios

### Endpoint correcto: `/api/fabric/viewer/export`
El export ahora usa `/api/data/export/excel` internamente (funciona con `format: 'gzip'`).

### Máximo de filas: 1,000,000
```typescript
exportStart(params: {
  schema_name: string;
  view: string;
  filters?: Record<string, string>;
  max_rows?: number;  // Ahora acepta hasta 1,000,000
  format?: 'gzip' | 'excel';
})
```

---

## 🔄 Formato de fechas en filtros

El backend ahora normaliza automáticamente las fechas. Puedes enviar:

| Formato enviado | Se convierte a |
|----------------|----------------|
| `06/07/2026` | `2026-07-06` |
| `06-07-2026` | `2026-07-06` |
| `2026-07-06` | (sin cambio) |

**Recomendación:** enviar siempre en ISO `yyyy-mm-dd` desde Angular:
```typescript
// En el filtro de fecha del componente
onDateFilter(col: string, date: Date) {
  const iso = date.toISOString().split('T')[0]; // "2026-07-06"
  this.updateFilter(col, iso);
}
```

---

## 🏗️ Flujo actualizado de paginación

```typescript
// Estado del componente
pageInfo = signal<PageInfo>({ total: 0, limit: 50, offset: 0, has_next: false });
loading = signal(false);

// Cargar datos
loadData() {
  this.loading.set(true);
  const limit = this.pageInfo().limit;

  this.fabricService.queryData({
    schema_name: this.currentSchema(),
    view: this.currentView(),
    columns: [],
    filters: this.filters(),
    limit: limit,
    offset: this.pageInfo().offset,
    sort_col: this.sortCol(),
    sort_dir: this.sortDir(),
    skip_count: limit > 1000,  // Auto skip_count para datasets grandes
  }).subscribe({
    next: (res) => {
      this.rows.set(res.data);
      this.pageInfo.set(res.meta);
      this.loading.set(false);
    },
    error: (err) => {
      this.loading.set(false);
      if (err.status === 422) {
        // Vista pesada sin filtros
        this.showFilterRequired.set(true);
      } else if (err.status === 429) {
        // Rate limit
        this.snackBar.open(`Demasiadas solicitudes. Reintente en ${err.error.retry_after}s`, 'OK');
      } else if (err.status === 503) {
        // Servicio no disponible (circuit breaker o Fabric timeout)
        this.snackBar.open('Servicio no disponible. Reintente en unos segundos.', 'OK');
      }
    }
  });
}

// Paginación
nextPage() {
  if (this.pageInfo().has_next) {
    this.pageInfo.update(p => ({ ...p, offset: p.offset + p.limit }));
    this.loadData();
  }
}

prevPage() {
  if (this.pageInfo().offset > 0) {
    this.pageInfo.update(p => ({ ...p, offset: Math.max(0, p.offset - p.limit) }));
    this.loadData();
  }
}
```

---

## 📋 Template de paginador

```html
<!-- Paginador adaptativo -->
<div class="paginator">
  <!-- Cuando tiene total calculado: paginador numérico -->
  @if (pageInfo().total > 0) {
    <span>{{ pageInfo().total | number }} registros</span>
    <span>Página {{ currentPage() }} de {{ totalPages() }}</span>
  }
  
  <!-- Cuando total = -1: solo botones prev/next -->
  @if (pageInfo().total === -1) {
    <span class="text-warning">Vista con muchos registros</span>
  }

  <button [disabled]="!canGoPrev()" (click)="prevPage()">← Anterior</button>
  <button [disabled]="!canGoNext()" (click)="nextPage()">Siguiente →</button>
</div>

<!-- Mensaje para vistas pesadas sin filtro -->
@if (showFilterRequired()) {
  <div class="alert alert-warning">
    <strong>⚠️ Esta vista requiere filtros</strong>
    <p>Tiene más de 1 millón de registros. Filtre por: {{ requiredFilters().join(', ') }}</p>
  </div>
}
```

---

## ⚠️ Manejo de errores actualizado

```typescript
// En el interceptor global o en el servicio
handleFabricError(error: HttpErrorResponse): string {
  switch (error.status) {
    case 422:
      // Filtros obligatorios — propagar suggestions y columns
      if (error.error?.requires_filters) {
        return error.error.message;
      }
      return error.error?.message ?? 'Filtros requeridos para esta vista.';
    case 429:
      return `Rate limit. Reintente en ${error.error?.retry_after ?? 60}s.`;
    case 503:
      // Puede ser circuit breaker O error de la fuente de datos
      if (error.error?.detail?.includes('Conversion failed')) {
        return 'Error en la fuente de datos: formato de fecha incompatible en la vista. Contacte al administrador de Fabric.';
      }
      return 'Microsoft Fabric no disponible. Reintente en 1 minuto.';
    case 504:
      return 'La consulta tardó demasiado. Aplique más filtros.';
    default:
      return 'Error inesperado consultando datos.';
  }
}
```

### Indicador visual de vista pesada

Cuando `page_info.heavy_view === true` (respuesta 200 exitosa con filtros aplicados):

```html
<!-- Indicador de vista pesada -->
@if (isHeavyView()) {
  <div class="badge badge-warning">
    <mat-icon>warning</mat-icon>
    Vista con alto volumen de datos — los filtros mejoran el rendimiento
  </div>
}
```

---

## 📊 Resumen de cambios

| Cambio | Impacto |
|--------|---------|
| `skip_count` | Vistas de 7.4M: de 150 seg a 5 seg |
| HEAVY_VIEWS validation | Evita consultas sin filtro que tardan minutos |
| Rate limit 60/min | Más espacio para navegación intensiva |
| Export 1M filas | Pueden exportar datasets más grandes |
| Normalización fechas | No más error "Conversion failed" en SQL Server |
| Timeout 503/504 | Mensajes claros al usuario cuando Fabric tarda |
| Detección dinámica heavy | Ya no depende de lista estática — auto-detecta >1M |
| Panel filtros con tipos | Renderiza inputs según tipo de columna (date/text/number) |
| heavy_view indicador | Badge visual cuando la vista es pesada (200 con datos) |

---

**Última actualización:** 7 de Julio de 2026


---

## 🔤 Tipos de columnas y renderizado dinámico

El endpoint `POST /viewer/columns` devuelve el tipo de cada columna:

```json
{
  "columns": [
    { "name": "codigo", "type": "varchar", "nullable": true },
    { "name": "cantidad", "type": "int", "nullable": true },
    { "name": "FechaFactura", "type": "datetime2", "nullable": true },
    { "name": "monto", "type": "decimal", "nullable": true },
    { "name": "activo", "type": "bit", "nullable": false }
  ]
}
```

### Mapeo de tipos SQL → componente de filtro en Angular

```typescript
// column-type.helper.ts

export type ColumnType = 'text' | 'number' | 'date' | 'boolean';

const TYPE_MAP: Record<string, ColumnType> = {
  // Texto
  'varchar': 'text',
  'nvarchar': 'text',
  'char': 'text',
  'nchar': 'text',
  'text': 'text',
  'ntext': 'text',
  'uniqueidentifier': 'text',

  // Números
  'int': 'number',
  'bigint': 'number',
  'smallint': 'number',
  'tinyint': 'number',
  'decimal': 'number',
  'numeric': 'number',
  'float': 'number',
  'real': 'number',
  'money': 'number',
  'smallmoney': 'number',

  // Fechas
  'datetime': 'date',
  'datetime2': 'date',
  'date': 'date',
  'time': 'text',
  'datetimeoffset': 'date',
  'smalldatetime': 'date',

  // Booleanos
  'bit': 'boolean',
};

export function getColumnType(sqlType: string): ColumnType {
  return TYPE_MAP[sqlType.toLowerCase()] ?? 'text';
}
```

### Uso en la tabla dinámica

```typescript
// En el componente de tabla
@for (col of columns(); track col.name) {
  <th>
    {{ col.name | translateColumn }}

    <!-- Filtro según tipo -->
    @switch (getColumnType(col.type)) {
      @case ('text') {
        <input type="text" (input)="onFilterChange(col.name, $event.target.value)" placeholder="Buscar...">
      }
      @case ('number') {
        <input type="number" (input)="onFilterChange(col.name, $event.target.value)">
      }
      @case ('date') {
        <input type="date" (change)="onFilterChange(col.name, $event.target.value)">
      }
      @case ('boolean') {
        <select (change)="onFilterChange(col.name, $event.target.value)">
          <option value="">Todos</option>
          <option value="1">Sí</option>
          <option value="0">No</option>
        </select>
      }
    }
  </th>
}
```

### Formateo de celdas según tipo

```typescript
// cell-formatter.pipe.ts
import { Pipe, PipeTransform } from '@angular/core';

@Pipe({ name: 'formatCell', standalone: true })
export class FormatCellPipe implements PipeTransform {
  transform(value: any, type: string): string {
    if (value === null || value === undefined) return '—';

    switch (getColumnType(type)) {
      case 'number':
        return Number(value).toLocaleString('es-CO');
      case 'date':
        return new Date(value).toLocaleDateString('es-CO', {
          year: 'numeric', month: '2-digit', day: '2-digit',
          hour: '2-digit', minute: '2-digit'
        });
      case 'boolean':
        return value === 1 || value === true ? 'Sí' : 'No';
      default:
        return String(value);
    }
  }
}
```

---

## 🌐 Traducción de columnas y valores (en Angular)

La traducción se hace en el frontend para no afectar los filtros (que deben usar valores originales de la BD).

### Pipe para traducir nombres de columnas

```typescript
// translate-column.pipe.ts
import { Pipe, PipeTransform } from '@angular/core';

const COLUMN_TRANSLATIONS: Record<string, string> = {
  // Columnas comunes
  'Ingreso': 'Ingreso',
  'Identificacion': 'Identificación',
  'Paciente': 'Paciente',
  'Clinica': 'Clínica',
  'UnidadFuncional': 'Unidad Funcional',
  'Cama': 'Cama',
  'Fecha_Orden': 'Fecha Orden',
  'Orden': 'Orden',
  'Especialidad_Ordenada': 'Especialidad',
  'DiagnosticoPpal': 'Diagnóstico Principal',
  'Folio': 'Folio',
  'EstadoOrden': 'Estado',
  'Observaciones': 'Observaciones',
  'Profesional': 'Profesional',
  'Email': 'Correo',
  'FechaFactura': 'Fecha Factura',
  'FechaCita': 'Fecha Cita',
  'FechaInicial': 'Fecha Inicial',
  'FechaFinal': 'Fecha Final',
  'Fecha_ingreso': 'Fecha Ingreso',
  'NombrePaciente': 'Nombre Paciente',
  'NombreEspecialista': 'Especialista',
  'CentroAtencion': 'Centro Atención',
  'Sucursal': 'Sucursal',
  'NIT': 'NIT',
  'RazonSocial': 'Razón Social',
  'DiaAgenda': 'Día',
  'MesAgenda': 'Mes',
  'SOURCE': 'Origen',
  'codigo': 'Código',
  'producto': 'Producto',
  'almacen': 'Almacén',
  'cantidad': 'Cantidad',
  'estado': 'Estado',
  'lote': 'Lote',
  'fecha_vencimiento': 'Fecha Vencimiento',
};

@Pipe({ name: 'translateColumn', standalone: true })
export class TranslateColumnPipe implements PipeTransform {
  transform(columnName: string): string {
    return COLUMN_TRANSLATIONS[columnName] ?? this.humanize(columnName);
  }

  // Convierte CamelCase/snake_case a texto legible
  private humanize(name: string): string {
    return name
      .replace(/_/g, ' ')
      .replace(/([a-z])([A-Z])/g, '$1 $2')
      .replace(/^./, s => s.toUpperCase());
  }
}
```

### Pipe para traducir valores (días, meses, estados)

```typescript
// translate-value.pipe.ts
import { Pipe, PipeTransform } from '@angular/core';

const VALUE_TRANSLATIONS: Record<string, Record<string, string>> = {
  // Días de la semana
  days: {
    'Monday': 'Lunes',
    'Tuesday': 'Martes',
    'Wednesday': 'Miércoles',
    'Thursday': 'Jueves',
    'Friday': 'Viernes',
    'Saturday': 'Sábado',
    'Sunday': 'Domingo',
  },
  // Meses
  months: {
    'January': 'Enero',
    'February': 'Febrero',
    'March': 'Marzo',
    'April': 'Abril',
    'May': 'Mayo',
    'June': 'Junio',
    'July': 'Julio',
    'August': 'Agosto',
    'September': 'Septiembre',
    'October': 'Octubre',
    'November': 'Noviembre',
    'December': 'Diciembre',
  },
  // Estados comunes
  status: {
    'ACTIVE': 'Activo',
    'INACTIVE': 'Inactivo',
    'ACTIVO': 'Activo',
    'INACTIVO': 'Inactivo',
    'PENDING': 'Pendiente',
    'COMPLETED': 'Completado',
    'CANCELLED': 'Cancelado',
    'SOLICITADO': 'Solicitado',
    'ANULADO': 'Anulado',
  },
};

// Columnas que se deben traducir automáticamente
const AUTO_TRANSLATE_COLUMNS: Record<string, string> = {
  'DiaAgenda': 'days',
  'DiaSemana': 'days',
  'MesAgenda': 'months',
  'Mes': 'months',
  'Estado': 'status',
  'EstadoOrden': 'status',
  'estado': 'status',
};

@Pipe({ name: 'translateValue', standalone: true })
export class TranslateValuePipe implements PipeTransform {
  /**
   * Uso: {{ valor | translateValue:'DiaAgenda' }}
   * Si la columna está en AUTO_TRANSLATE_COLUMNS, traduce automáticamente.
   */
  transform(value: any, columnName?: string): string {
    if (value === null || value === undefined) return '—';

    const strValue = String(value).trim();

    if (columnName && AUTO_TRANSLATE_COLUMNS[columnName]) {
      const dictKey = AUTO_TRANSLATE_COLUMNS[columnName];
      const dict = VALUE_TRANSLATIONS[dictKey];
      return dict?.[strValue] ?? strValue;
    }

    // Intentar en todas las categorías
    for (const dict of Object.values(VALUE_TRANSLATIONS)) {
      if (dict[strValue]) return dict[strValue];
    }

    return strValue;
  }
}
```

### Uso en la tabla

```html
<!-- En la celda de la tabla -->
@for (col of columns(); track col.name) {
  <td>
    {{ row[col.name] | translateValue:col.name | formatCell:col.type }}
  </td>
}
```

### Regla importante para filtros

Los filtros SIEMPRE usan el valor original (en inglés) porque la BD tiene esos valores:

```typescript
// ❌ INCORRECTO — no va a encontrar nada en Fabric
filters: { "DiaAgenda": "Jueves" }

// ✅ CORRECTO — Fabric tiene el dato en inglés
filters: { "DiaAgenda": "Thursday" }
```

Si quieres un dropdown de filtro traducido:
```html
<select (change)="onFilterChange('DiaAgenda', $event.target.value)">
  <option value="">Todos los días</option>
  <option value="Monday">Lunes</option>
  <option value="Tuesday">Martes</option>
  <option value="Wednesday">Miércoles</option>
  <option value="Thursday">Jueves</option>
  <option value="Friday">Viernes</option>
  <option value="Saturday">Sábado</option>
  <option value="Sunday">Domingo</option>
</select>
```

El `value` es el original (inglés) que se envía al backend, pero el texto visible es en español.

---

**Última actualización:** 7 de Julio de 2026
