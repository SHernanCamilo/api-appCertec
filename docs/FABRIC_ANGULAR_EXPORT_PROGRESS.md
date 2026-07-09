# 📥 Export Excel — Mejora opcional de UX en Angular

**Fecha:** 9 de Julio de 2026  
**Impacto:** Solo UX — el export funciona igual sin estos cambios

---

## ¿Qué cambió en el backend?

El export ahora genera **Excel .xlsx** (antes era .ndjson.gz) y reporta **progreso real** durante la generación.

**Los endpoints NO cambiaron** — Angular sigue llamando los mismos 3 endpoints:

```
POST /api/fabric/viewer/export/start   → 202 {job_id}
GET  /api/fabric/viewer/export/status/{id}  → {status, progress, rows, ...}
GET  /api/fabric/viewer/export/download/{id} → archivo .xlsx
```

---

## Respuesta mejorada de /export/status

### Antes:
```json
{
  "success": true,
  "data": {
    "status": "completed",
    "filename": "ca_VW_Portfolio_CruceCartera_20260709.ndjson.gz"
  }
}
```

### Ahora:
```json
{
  "success": true,
  "data": {
    "status": "processing",
    "progress": 45,
    "rows": 2500,
    "chunks": 1,
    "message": "Descargando datos...",
    "schema": "ca",
    "view": "VW_Portfolio_CruceCartera",
    "format": "xlsx",
    "created_at": "2026-07-09T10:30:00-05:00",
    "updated_at": "2026-07-09T10:30:15-05:00"
  }
}
```

Cuando termina:
```json
{
  "success": true,
  "data": {
    "status": "completed",
    "progress": 100,
    "rows": 5000,
    "filename": "ca_VW_Portfolio_CruceCartera_20260709_103025.xlsx",
    "file_size": 886840,
    "file_size_human": "866.1 KB",
    "format": "xlsx"
  }
}
```

---

## Mejora opcional: barra de progreso

Si quieren reemplazar el spinner por una barra de progreso real:

```typescript
// export-progress.component.ts

// Polling cada 2 segundos para ver el progreso
pollExportStatus(jobId: string) {
  const poll = interval(2000).pipe(
    switchMap(() => this.http.get<any>(`/api/fabric/viewer/export/status/${jobId}`)),
    takeWhile(res => res.data.status === 'processing' || res.data.status === 'pending', true),
  );

  poll.subscribe(res => {
    const data = res.data;

    if (data.status === 'processing') {
      this.progress = data.progress;   // 0-100
      this.rows = data.rows;           // filas procesadas
      this.message = data.message;     // "Descargando datos..." / "Generando Excel..."
    }

    if (data.status === 'completed') {
      this.progress = 100;
      this.rows = data.rows;
      this.filename = data.filename;
      this.fileSize = data.file_size_human;
      this.showDownloadButton = true;
    }

    if (data.status === 'failed') {
      this.error = data.message;
    }
  });
}
```

### Template:

```html
<!-- Barra de progreso durante export -->
@if (exporting) {
  <div class="export-progress">
    <div class="progress" style="height: 24px;">
      <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
           [style.width.%]="progress">
        {{ progress }}%
      </div>
    </div>
    <small class="text-muted mt-1">
      {{ rows | number }} filas procesadas — {{ message }}
    </small>
  </div>
}

<!-- Botón de descarga cuando termina -->
@if (showDownloadButton) {
  <div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-file-earmark-excel"></i>
    <span>{{ filename }} ({{ fileSize }})</span>
    <a [href]="downloadUrl" class="btn btn-sm btn-success ms-auto">
      <i class="bi bi-download"></i> Descargar
    </a>
  </div>
}
```

---

## Lo que NO necesita cambios

| Funcionalidad | Estado |
|---------------|--------|
| Botón "Exportar Excel" | ✅ Funciona igual |
| Llamada a `/export/start` | ✅ Mismos parámetros |
| Polling a `/export/status` | ✅ Compatible (campos extras son opcionales) |
| Descarga `/export/download` | ✅ Ahora baja .xlsx en vez de .ndjson.gz |
| El browser abre .xlsx con Excel | ✅ Automático por Content-Type |

---

## Resumen

- **¿Obligatorio ajustar Angular?** No. Funciona igual.
- **¿Vale la pena?** Sí, solo para mostrar la barra de progreso con `progress` y `rows`.
- **¿Cambia el archivo descargado?** Sí: ahora es `.xlsx` nativo (Excel lo abre directo, con formato corporativo JadeOne).

---

*Última actualización: 9 de Julio de 2026*
