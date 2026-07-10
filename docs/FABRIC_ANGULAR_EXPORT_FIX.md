# 📥 Fix Botón "Descargar Excel" — Angular

**Fecha:** 10 de Julio de 2026  
**Prioridad:** Alta — el export funciona en backend pero el frontend no lo dispara correctamente

---

## Problema actual

El botón "Descargar Excel" hace un GET sin parámetros:

```
GET /api/fabric/viewer/export/start  ← SIN schema_name ni view
```

Resultado: error **"Parámetros requeridos: schema_name y view"**

El backend genera el archivo correctamente (log confirma 109K filas exportadas), pero el frontend nunca lo descarga porque:
1. No envía los parámetros necesarios
2. No hace polling al status del job
3. No dispara la descarga cuando termina

---

## Solución: 3 pasos

### Paso 1: Servicio de Export

```typescript
// services/fabric-export.service.ts
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, interval, switchMap, takeWhile, filter } from 'rxjs';
import { environment } from '@env/environment';

export interface ExportStatus {
  status: 'pending' | 'processing' | 'completed' | 'failed';
  progress: number;
  rows: number;
  message?: string;
  filename?: string;
  file_size_human?: string;
  format?: string;
}

@Injectable({ providedIn: 'root' })
export class FabricExportService {
  private baseUrl = `${environment.apiUrl}/fabric/viewer/export`;

  // Estado observable para la barra de progreso
  exportStatus$ = new BehaviorSubject<ExportStatus | null>(null);

  constructor(private http: HttpClient) {}

  /**
   * Iniciar export y hacer polling automático.
   * Retorna el job_id inmediatamente.
   */
  startExport(params: {
    schema_name: string;
    view: string;
    filters?: Record<string, any>;
    columns?: string[];
    sort_col?: string;
    sort_dir?: string;
    max_rows?: number;
  }): void {
    // Reset estado
    this.exportStatus$.next({
      status: 'pending',
      progress: 0,
      rows: 0,
      message: 'Iniciando export...'
    });

    // 1. Llamar POST con los parámetros
    this.http.post<{ job_id: string; success: boolean }>(
      `${this.baseUrl}/start`,
      params
    ).subscribe({
      next: (res) => {
        if (!res.success || !res.job_id) {
          this.exportStatus$.next({
            status: 'failed',
            progress: 0,
            rows: 0,
            message: 'Error iniciando export'
          });
          return;
        }

        // 2. Polling cada 3 segundos
        this.pollStatus(res.job_id);
      },
      error: (err) => {
        this.exportStatus$.next({
          status: 'failed',
          progress: 0,
          rows: 0,
          message: err.error?.message || 'Error de conexión'
        });
      }
    });
  }

  /**
   * Polling al status hasta que complete o falle.
   */
  private pollStatus(jobId: string): void {
    interval(3000).pipe(
      switchMap(() => this.http.get<{ success: boolean; data: ExportStatus }>(
        `${this.baseUrl}/status/${jobId}`
      )),
      filter(res => res.success),
      takeWhile(res => {
        const status = res.data.status;
        return status === 'pending' || status === 'processing';
      }, true), // inclusive: emite el último (completed/failed)
    ).subscribe({
      next: (res) => {
        const data = res.data;
        this.exportStatus$.next(data);

        // Si completó, disparar descarga automática
        if (data.status === 'completed') {
          this.downloadFile(jobId);
        }
      },
      error: () => {
        this.exportStatus$.next({
          status: 'failed',
          progress: 0,
          rows: 0,
          message: 'Error consultando estado del export'
        });
      }
    });
  }

  /**
   * Descargar el archivo generado.
   */
  downloadFile(jobId: string): void {
    const url = `${this.baseUrl}/download/${jobId}`;
    window.open(url, '_blank');
  }

  /**
   * Resetear estado (cuando el usuario cierra la barra).
   */
  reset(): void {
    this.exportStatus$.next(null);
  }
}
```

---

### Paso 2: Componente de barra de progreso

```typescript
// components/export-progress/export-progress.component.ts
import { Component } from '@angular/core';
import { FabricExportService, ExportStatus } from '../../services/fabric-export.service';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-export-progress',
  standalone: true,
  imports: [CommonModule],
  template: `
    <!-- Solo mostrar cuando hay un export en curso -->
    <div *ngIf="status" class="export-progress-bar">

      <!-- Procesando -->
      <div *ngIf="status.status === 'pending' || status.status === 'processing'"
           class="alert alert-info d-flex align-items-center gap-3 mb-0 py-2">
        <div class="spinner-border spinner-border-sm text-primary"></div>
        <div class="flex-grow-1">
          <div class="progress" style="height: 20px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated"
                 [style.width.%]="status.progress"
                 [class.bg-info]="status.progress < 50"
                 [class.bg-primary]="status.progress >= 50">
              {{ status.progress }}%
            </div>
          </div>
          <small class="text-muted">
            {{ status.message || 'Exportando...' }}
            <span *ngIf="status.rows"> — {{ status.rows | number }} filas</span>
          </small>
        </div>
      </div>

      <!-- Completado -->
      <div *ngIf="status.status === 'completed'"
           class="alert alert-success d-flex align-items-center gap-2 mb-0 py-2">
        <i class="bi bi-check-circle-fill"></i>
        <span>
          Export listo: <strong>{{ status.filename }}</strong>
          ({{ status.file_size_human }} — {{ status.rows | number }} filas)
        </span>
        <button class="btn btn-sm btn-outline-success ms-auto" (click)="dismiss()">
          <i class="bi bi-x"></i>
        </button>
      </div>

      <!-- Error -->
      <div *ngIf="status.status === 'failed'"
           class="alert alert-danger d-flex align-items-center gap-2 mb-0 py-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>Error: {{ status.message }}</span>
        <button class="btn btn-sm btn-outline-danger ms-auto" (click)="dismiss()">
          <i class="bi bi-x"></i>
        </button>
      </div>
    </div>
  `,
  styles: [`
    .export-progress-bar {
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 9999;
      min-width: 400px;
      max-width: 500px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      border-radius: 8px;
      overflow: hidden;
    }
  `]
})
export class ExportProgressComponent {
  status: ExportStatus | null = null;

  constructor(private exportService: FabricExportService) {
    this.exportService.exportStatus$.subscribe(s => this.status = s);
  }

  dismiss(): void {
    this.exportService.reset();
  }
}
```

---

### Paso 3: Ajustar el botón "Descargar Excel" en el componente de la tabla

```typescript
// En el componente donde está el botón "Descargar Excel"
import { FabricExportService } from '../../services/fabric-export.service';

export class DataViewerComponent {
  // ... propiedades existentes ...
  currentSchema = '';   // ej: 'dc'
  currentView = '';     // ej: 'VW_HC_GestantesRegistroTipo5_Nva'
  activeFilters = {};   // filtros aplicados actualmente

  constructor(
    private exportService: FabricExportService,
    // ... otros servicios ...
  ) {}

  /**
   * ✅ CORRECTO — Botón "Descargar Excel"
   * Llama POST con schema, view y filtros actuales.
   */
  onExportClick(): void {
    this.exportService.startExport({
      schema_name: this.currentSchema,
      view: this.currentView,
      filters: this.activeFilters,
      columns: [],          // [] = todas las columnas
      sort_col: this.sortCol || '',
      sort_dir: this.sortDir || 'asc',
      max_rows: 500000,     // máximo 500K filas
    });
  }
}
```

**En el template:**
```html
<!-- Botón de export -->
<button class="btn btn-success" (click)="onExportClick()">
  <i class="bi bi-file-earmark-excel"></i> Descargar Excel
</button>

<!-- Componente de progreso (ponerlo en el layout principal o aquí) -->
<app-export-progress></app-export-progress>
```

---

## Flujo completo (cómo funciona)

```
1. Usuario hace clic en "Descargar Excel"
   ↓
2. Angular: POST /api/fabric/viewer/export/start
   Body: { schema_name: "dc", view: "VW_HC_...", filters: {} }
   ↓
3. Backend responde: 202 { job_id: "exp_stream_abc123" }
   ↓
4. Angular: inicia polling cada 3 segundos
   GET /api/fabric/viewer/export/status/exp_stream_abc123
   ↓
5. Respuestas del polling:
   { status: "processing", progress: 15, rows: 15000, message: "Exportando... (15000 filas)" }
   { status: "processing", progress: 45, rows: 45000, message: "Exportando... (45000 filas)" }
   { status: "processing", progress: 75, rows: 75000, message: "Generando archivo Excel..." }
   { status: "completed", progress: 100, rows: 109145, filename: "dc_VW_HC_...csv", file_size_human: "95.9 MB" }
   ↓
6. Angular detecta "completed" → abre ventana de descarga:
   window.open('/api/fabric/viewer/export/download/exp_stream_abc123', '_blank')
   ↓
7. Browser descarga el archivo .csv / .xlsx
```

---

## Respuestas del endpoint /export/status

### Pendiente (recién creado)
```json
{
  "success": true,
  "data": {
    "status": "pending",
    "progress": 0,
    "rows": 0,
    "schema": "dc",
    "view": "VW_HC_GestantesRegistroTipo5_Nva",
    "format": "xlsx"
  }
}
```

### Procesando (descargando datos de Fabric)
```json
{
  "success": true,
  "data": {
    "status": "processing",
    "progress": 45,
    "rows": 45000,
    "message": "Descargando datos... (45000 filas)"
  }
}
```

### Procesando (generando archivo)
```json
{
  "success": true,
  "data": {
    "status": "processing",
    "progress": 92,
    "rows": 109145,
    "message": "Generando archivo Excel..."
  }
}
```

### Completado
```json
{
  "success": true,
  "data": {
    "status": "completed",
    "progress": 100,
    "rows": 109145,
    "filename": "dc_VW_HC_GestantesRegistroTipo5_Nva_20260710_070636.csv",
    "file_path": "fabric_exports/exp_stream_abc123/dc_VW_HC_...csv",
    "file_size": 100656563,
    "file_size_human": "95.9 MB",
    "format": "csv"
  }
}
```

### Error
```json
{
  "success": true,
  "data": {
    "status": "failed",
    "message": "Graph-Fabric HTTP 503: Connection timeout",
    "rows": 0
  }
}
```

---

## Endpoints resumen

| Método | URL | Parámetros | Respuesta |
|--------|-----|-----------|-----------|
| POST | `/api/fabric/viewer/export/start` | Body: `{schema_name, view, filters, columns, max_rows}` | `202 {job_id, success}` |
| GET | `/api/fabric/viewer/export/status/{jobId}` | — | `{success, data: {status, progress, rows, ...}}` |
| GET | `/api/fabric/viewer/export/download/{jobId}` | — | Archivo (CSV o XLSX) |

---

## Formato del archivo descargado

| Filas | Formato | Extensión | Cómo abre Excel |
|-------|---------|-----------|-----------------|
| ≤ 20,000 | Excel real (.xlsx) | `.xlsx` | Doble-clic directo |
| > 20,000 | CSV con separador `;` | `.csv` | Doble-clic → Excel lo abre como tabla |

El CSV incluye:
- `sep=;` en la primera línea (Excel lo detecta automáticamente)
- BOM UTF-8 (acentos y ñ se ven correctos)
- Campos con saltos de línea se limpian (reemplazados por espacio)

---

## Checklist para el equipo Angular

```
[ ] Crear FabricExportService con startExport(), pollStatus(), downloadFile()
[ ] Crear ExportProgressComponent (barra flotante en esquina inferior derecha)
[ ] Cambiar botón "Descargar Excel" para que llame exportService.startExport()
[ ] Enviar schema_name y view en el body del POST (NO como GET sin params)
[ ] Polling cada 3 segundos al /export/status/{jobId}
[ ] Cuando status === 'completed' → window.open(download_url)
[ ] Mostrar progreso real: porcentaje + filas descargadas
[ ] Manejar status === 'failed' → mostrar error al usuario
[ ] Registrar ExportProgressComponent en el módulo/layout principal
[ ] Botón deshabilitado mientras hay un export en curso (evitar duplicados)
```

---

## Errores comunes y soluciones

| Error | Causa | Solución |
|-------|-------|----------|
| "Parámetros requeridos: schema_name y view" | Frontend hace GET sin body | Usar POST con `{schema_name, view}` |
| "GET method is not supported" | Ruta solo acepta POST | Ruta ya acepta GET y POST (arreglado) |
| Barra queda en 0% | No hace polling | Implementar interval cada 3s |
| "Archivo no encontrado" | Export expiró (30 min) | Descargar antes de 30 min |
| Excel dice "formato inválido" | Archivo es CSV con extensión .xlsx | Ya corregido: >20K filas usa .csv |

---

*Última actualización: 10 de Julio de 2026*
