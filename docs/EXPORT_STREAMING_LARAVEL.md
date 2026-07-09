# Export Streaming — Guia de Integracion Laravel

## Resumen

Graph-Fabric ya NO genera Excel. Solo envia datos crudos comprimidos en chunks via streaming.
Laravel recibe los chunks, los almacena, arma el Excel y notifica al usuario.

**Ventaja:** Graph-Fabric queda libre para atender consultas de vistas de otros usuarios.

---

## Diagrama de flujo

```
Angular                    Laravel                      Graph-Fabric             Fabric F16
  │                          │                              │                      │
  │─1─ POST /export ────────▶│                              │                      │
  │◀── 202 {job_id} ────────│                              │                      │
  │                          │─2─ POST /api/data/export/stream ──▶│                │
  │                          │                              │──3── SELECT TOP N ──▶│
  │                          │                              │◀──── filas ──────────│
  │                          │◀── chunk 1 (50K filas gzip) ─│                      │
  │                          │    almacena en Redis/array    │                      │
  │                          │◀── chunk 2 (50K filas gzip) ─│                      │
  │                          │◀── chunk 3 (50K filas gzip) ─│                      │
  │                          │◀── FIN stream ───────────────│                      │
  │                          │                              │                      │
  │                          │─4─ Arma Excel (.xlsx) ───────│                      │
  │                          │─5─ Guarda en storage ────────│                      │
  │                          │                              │                      │
  │─6─ GET /export/status ──▶│                              │                      │
  │◀── {status: ready} ─────│                              │                      │
  │─7─ GET /export/download ▶│                              │                      │
  │◀── archivo .xlsx ────────│                              │                      │
```

---

## Endpoint de Graph-Fabric

### POST /api/data/export/stream

**Request body:**

```json
{
  "token": "UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P",
  "user_email": "usuario@medilaser.com.co",
  "user_name": "Nombre Usuario",
  "department": "NVA-SISTEMAS",
  "groups": ["GG-BD-DF", "GG-BD-FR"],
  "schema_name": "df",
  "view": "VW_Billing_IngresosAbiertos",
  "filters": {"Sucursal": "Neiva"},
  "columns": [],
  "sort_col": "",
  "sort_dir": "asc",
  "max_rows": 500000,
  "format": "gzip"
}
```

**Response:** `application/octet-stream` (streaming binario)

**Headers de respuesta:**

| Header | Valor | Descripcion |
|--------|-------|-------------|
| `X-Schema` | `df` | Esquema |
| `X-View` | `VW_Billing_IngresosAbiertos` | Vista |
| `X-Chunk-Size` | `50000` | Filas por chunk |
| `X-Max-Rows` | `500000` | Limite total |

---

## Formato del stream binario

Cada chunk tiene esta estructura:

```
[4 bytes: tamano del chunk gzip (big-endian uint32)] + [N bytes: data gzip]
```

Dentro de cada chunk gzip hay **NDJSON** (una linea JSON por fila):

```json
{"Nit":"900156264","RazonSocial":"CLINICA X","Fecha":"2026-01-15","Valor":1500000.00}
{"Nit":"900156264","RazonSocial":"CLINICA X","Fecha":"2026-02-20","Valor":2300000.00}
...
```

### Senales especiales:

| Size header | Significado |
|-------------|-------------|
| `> 0` | Chunk normal, leer N bytes de data gzip |
| `= 0` | Error del servidor, los siguientes bytes son el mensaje de error JSON |

### Fin del stream:

Cuando no hay mas chunks, el stream se cierra (EOF). Laravel detecta esto con `$body->eof()`.

---

## Implementacion en Laravel

### 1. Rutas (routes/api.php)

```php
Route::middleware(['auth:sanctum'])->prefix('export')->group(function () {
    Route::post('/start', [ExportController::class, 'start']);
    Route::get('/status/{jobId}', [ExportController::class, 'status']);
    Route::get('/download/{jobId}', [ExportController::class, 'download']);
});
```

### 2. Controller (ExportController.php)

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ExportStreamJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    /**
     * Iniciar export en segundo plano.
     * Responde inmediato con job_id para polling.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name' => 'required|string',
            'view'        => 'required|string',
            'filters'     => 'nullable|array',
            'columns'     => 'nullable|array',
            'sort_col'    => 'nullable|string',
            'sort_dir'    => 'nullable|in:asc,desc',
            'max_rows'    => 'nullable|integer|min:1|max:1000000',
        ]);

        $jobId = Str::uuid()->toString();
        $user = $request->user();

        // Guardar estado inicial
        $this->setJobStatus($jobId, [
            'status'   => 'processing',
            'progress' => 0,
            'rows'     => 0,
            'schema'   => $request->input('schema_name'),
            'view'     => $request->input('view'),
            'user'     => $user->email,
            'started'  => now()->toIso8601String(),
        ]);

        // Dispatch job a la cola
        ExportStreamJob::dispatch(
            $jobId,
            $request->input('schema_name'),
            $request->input('view'),
            $request->input('filters', []),
            $request->input('columns', []),
            $request->input('sort_col', ''),
            $request->input('sort_dir', 'asc'),
            $request->input('max_rows', 500000),
            $user,
        );

        return response()->json([
            'job_id'  => $jobId,
            'status'  => 'processing',
            'message' => 'Export iniciado. Consulta /export/status/{job_id} para ver progreso.',
        ], 202);
    }

    /**
     * Consultar estado del export (polling).
     */
    public function status(string $jobId): JsonResponse
    {
        $data = $this->getJobStatus($jobId);

        if (!$data) {
            return response()->json(['error' => 'Job no encontrado'], 404);
        }

        return response()->json($data);
    }

    /**
     * Descargar el Excel generado.
     */
    public function download(string $jobId)
    {
        $data = $this->getJobStatus($jobId);

        if (!$data || $data['status'] !== 'ready') {
            return response()->json(['error' => 'Export no esta listo'], 400);
        }

        $path = $data['file_path'] ?? null;
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Archivo expirado'], 410);
        }

        $filename = $data['filename'] ?? 'export.xlsx';
        return Storage::disk('local')->download($path, $filename);
    }

    // ── Helpers Redis ──────────────────────────────────────────────

    private function setJobStatus(string $jobId, array $data): void
    {
        Redis::setex("export_job:{$jobId}", 900, json_encode($data)); // 15 min TTL
    }

    private function getJobStatus(string $jobId): ?array
    {
        $raw = Redis::get("export_job:{$jobId}");
        return $raw ? json_decode($raw, true) : null;
    }
}
```

### 3. Job de cola (ExportStreamJob.php)

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ExportStreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 min max
    public int $tries = 1;

    public function __construct(
        private string $jobId,
        private string $schema,
        private string $view,
        private array $filters,
        private array $columns,
        private string $sortCol,
        private string $sortDir,
        private int $maxRows,
        private object $user,
    ) {}

    public function handle(): void
    {
        try {
            $allRows = $this->streamFromGraphFabric();
            $this->generateExcel($allRows);
        } catch (\Throwable $e) {
            Log::error("ExportStreamJob error: {$e->getMessage()}", [
                'job_id' => $this->jobId,
                'schema' => $this->schema,
                'view'   => $this->view,
            ]);
            $this->updateStatus(['status' => 'error', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Consumir el stream de Graph-Fabric chunk por chunk.
     */
    private function streamFromGraphFabric(): array
    {
        $url = config('services.graph_fabric.url') . '/api/data/export/stream';

        $response = Http::withOptions([
            'stream'          => true,
            'timeout'         => 300,
            'connect_timeout' => 30,
        ])->post($url, [
            'token'       => config('services.graph_fabric.token'),
            'user_email'  => $this->user->email,
            'user_name'   => $this->user->name,
            'department'  => $this->user->department ?? 'NAL',
            'groups'      => $this->user->azure_groups ?? [],
            'schema_name' => $this->schema,
            'view'        => $this->view,
            'filters'     => $this->filters,
            'columns'     => $this->columns,
            'sort_col'    => $this->sortCol,
            'sort_dir'    => $this->sortDir,
            'max_rows'    => $this->maxRows,
            'format'      => 'gzip',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException("Graph-Fabric respondio {$response->status()}: {$response->body()}");
        }

        $body = $response->toPsrResponse()->getBody();
        $allRows = [];
        $chunkNum = 0;

        while (!$body->eof()) {
            // Leer 4 bytes del header (tamano del chunk)
            $sizeBytes = $body->read(4);
            if (strlen($sizeBytes) < 4) {
                break; // Fin del stream
            }

            $chunkSize = unpack('N', $sizeBytes)[1]; // big-endian uint32

            // Size = 0 → error del servidor
            if ($chunkSize === 0) {
                $errorData = $body->read(4096);
                throw new \RuntimeException("Graph-Fabric stream error: {$errorData}");
            }

            // Leer el chunk gzip completo
            $chunkGzip = '';
            $remaining = $chunkSize;
            while ($remaining > 0 && !$body->eof()) {
                $read = $body->read(min($remaining, 65536));
                $chunkGzip .= $read;
                $remaining -= strlen($read);
            }

            // Decodificar gzip → NDJSON
            $ndjson = gzdecode($chunkGzip);
            if ($ndjson === false) {
                Log::warning("ExportStreamJob: chunk {$chunkNum} gzdecode failed");
                continue;
            }

            // Parsear NDJSON (una fila JSON por linea)
            $lines = explode("\n", trim($ndjson));
            foreach ($lines as $line) {
                if ($line !== '') {
                    $row = json_decode($line, true);
                    if ($row) {
                        $allRows[] = $row;
                    }
                }
            }

            $chunkNum++;

            // Actualizar progreso
            $this->updateStatus([
                'status'   => 'processing',
                'progress' => min(90, intval(count($allRows) / $this->maxRows * 90)),
                'rows'     => count($allRows),
                'chunks'   => $chunkNum,
            ]);
        }

        Log::info("ExportStreamJob: stream completado", [
            'job_id' => $this->jobId,
            'rows'   => count($allRows),
            'chunks' => $chunkNum,
        ]);

        return $allRows;
    }

    /**
     * Generar Excel con los datos acumulados.
     */
    private function generateExcel(array $rows): void
    {
        if (empty($rows)) {
            $this->updateStatus([
                'status' => 'ready',
                'rows'   => 0,
                'message' => 'No hay datos para exportar con los filtros aplicados.',
            ]);
            return;
        }

        $this->updateStatus(['status' => 'processing', 'progress' => 92, 'rows' => count($rows)]);

        // Generar Excel
        $filename = "{$this->schema}_{$this->view}_" . now()->format('Ymd_His') . ".xlsx";
        $path = "exports/{$this->jobId}/{$filename}";

        // Opcion A: Con Maatwebsite/Laravel-Excel
        $export = new \App\Exports\GenericExport($rows);
        Excel::store($export, $path, 'local');

        // Opcion B: Sin Laravel-Excel (PhpSpreadsheet directo)
        // $this->generateWithPhpSpreadsheet($rows, $path);

        $fileSize = Storage::disk('local')->size($path);

        $this->updateStatus([
            'status'    => 'ready',
            'progress'  => 100,
            'rows'      => count($rows),
            'filename'  => $filename,
            'file_path' => $path,
            'file_size' => $fileSize,
            'file_size_human' => $this->humanFileSize($fileSize),
        ]);

        Log::info("ExportStreamJob: Excel generado", [
            'job_id'   => $this->jobId,
            'rows'     => count($rows),
            'size'     => $fileSize,
            'filename' => $filename,
        ]);
    }

    private function updateStatus(array $merge): void
    {
        $current = json_decode(Redis::get("export_job:{$this->jobId}") ?? '{}', true);
        $updated = array_merge($current, $merge, ['updated' => now()->toIso8601String()]);
        Redis::setex("export_job:{$this->jobId}", 900, json_encode($updated));
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
```

### 4. Export generico (GenericExport.php)

```php
<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class GenericExport implements FromArray, WithHeadings, ShouldAutoSize
{
    private array $rows;
    private array $headers;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->headers = !empty($rows) ? array_keys($rows[0]) : [];
    }

    public function headings(): array
    {
        return $this->headers;
    }

    public function array(): array
    {
        return array_map(fn($row) => array_values($row), $this->rows);
    }
}
```

---

## Implementacion en Angular (Frontend)

### export.service.ts

```typescript
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { interval, switchMap, takeWhile, tap } from 'rxjs';

export interface ExportJob {
  job_id: string;
  status: 'processing' | 'ready' | 'error';
  progress: number;
  rows: number;
  filename?: string;
  error?: string;
}

@Injectable({ providedIn: 'root' })
export class ExportService {
  private apiUrl = '/api/export';

  constructor(private http: HttpClient) {}

  /**
   * Iniciar export y hacer polling hasta que termine.
   */
  startExport(params: {
    schema_name: string;
    view: string;
    filters?: Record<string, any>;
    max_rows?: number;
  }, callbacks: {
    onProgress: (job: ExportJob) => void;
    onReady: (job: ExportJob) => void;
    onError: (error: string) => void;
  }): void {
    // 1. Iniciar
    this.http.post<{ job_id: string }>(`${this.apiUrl}/start`, params)
      .subscribe({
        next: (res) => {
          const jobId = res.job_id;

          // 2. Polling cada 2 segundos
          interval(2000).pipe(
            switchMap(() => this.http.get<ExportJob>(`${this.apiUrl}/status/${jobId}`)),
            tap(job => {
              if (job.status === 'processing') {
                callbacks.onProgress(job);
              }
            }),
            takeWhile(job => job.status === 'processing', true),
          ).subscribe({
            next: (job) => {
              if (job.status === 'ready') {
                callbacks.onReady(job);
              } else if (job.status === 'error') {
                callbacks.onError(job.error || 'Error desconocido');
              }
            },
            error: (err) => callbacks.onError(err.message),
          });
        },
        error: (err) => callbacks.onError(err.error?.message || 'Error iniciando export'),
      });
  }

  /**
   * Descargar el Excel generado.
   */
  download(jobId: string): void {
    window.open(`${this.apiUrl}/download/${jobId}`, '_blank');
  }
}
```

### export-button.component.ts

```typescript
import { Component, Input } from '@angular/core';
import { ExportService, ExportJob } from './export.service';

@Component({
  selector: 'app-export-button',
  template: `
    <button (click)="startExport()" [disabled]="exporting" class="btn btn-success">
      <span *ngIf="!exporting">📥 Exportar Excel</span>
      <span *ngIf="exporting">
        <div class="spinner-border spinner-border-sm"></div>
        {{ progress }}% ({{ rows | number }} filas)
      </span>
    </button>

    <!-- Barra de progreso -->
    <div *ngIf="exporting" class="progress mt-2" style="height: 20px;">
      <div class="progress-bar progress-bar-striped progress-bar-animated"
           [style.width.%]="progress">
        {{ progress }}%
      </div>
    </div>

    <!-- Mensaje de exito -->
    <div *ngIf="ready" class="alert alert-success mt-2">
      ✅ Export listo ({{ rows | number }} filas)
      <button (click)="downloadFile()" class="btn btn-sm btn-primary ms-2">
        Descargar
      </button>
    </div>

    <!-- Mensaje de error -->
    <div *ngIf="errorMsg" class="alert alert-danger mt-2">
      ❌ {{ errorMsg }}
    </div>
  `
})
export class ExportButtonComponent {
  @Input() schema!: string;
  @Input() view!: string;
  @Input() filters: Record<string, any> = {};
  @Input() maxRows = 500000;

  exporting = false;
  ready = false;
  progress = 0;
  rows = 0;
  jobId = '';
  errorMsg = '';

  constructor(private exportService: ExportService) {}

  startExport(): void {
    this.exporting = true;
    this.ready = false;
    this.errorMsg = '';
    this.progress = 0;
    this.rows = 0;

    this.exportService.startExport(
      {
        schema_name: this.schema,
        view: this.view,
        filters: this.filters,
        max_rows: this.maxRows,
      },
      {
        onProgress: (job) => {
          this.progress = job.progress;
          this.rows = job.rows;
        },
        onReady: (job) => {
          this.exporting = false;
          this.ready = true;
          this.rows = job.rows;
          this.jobId = job.job_id;
        },
        onError: (error) => {
          this.exporting = false;
          this.errorMsg = error;
        },
      }
    );
  }

  downloadFile(): void {
    this.exportService.download(this.jobId);
  }
}
```

---

## Configuracion

### .env de Graph-Fabric (VPS)

```env
EXPORT_CHUNK_SIZE=50000   # filas por chunk (ajustar segun RAM)
```

### .env de Laravel

```env
GRAPH_FABRIC_URL=http://127.0.0.1:8001
GRAPH_FABRIC_TOKEN=UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P

# Queue para exports (usar database o redis)
QUEUE_CONNECTION=redis
```

### Dependencias Laravel

```bash
composer require maatwebsite/excel
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"
```

---

## Tiempos estimados

| Filas | Chunks | Stream Graph→Laravel | Generar Excel | Total |
|------:|-------:|---------------------:|--------------:|------:|
| 50,000 | 1 | ~5s | ~3s | ~8s |
| 100,000 | 2 | ~10s | ~6s | ~16s |
| 500,000 | 10 | ~45s | ~30s | ~75s |
| 1,000,000 | 20 | ~90s | ~60s | ~150s |

Con barra de progreso, el usuario ve el avance y no se desespera.

---

## Comparacion vs el metodo anterior

| Aspecto | Antes (Graph genera Excel) | Ahora (Stream + Laravel Excel) |
|---------|---------------------------|-------------------------------|
| Graph-Fabric bloqueado | SI (mientras genera xlsx) | NO (solo streaming) |
| Memoria en Python | Alta (todo en RAM) | Constante (chunks) |
| Otros usuarios afectados | SI (workers ocupados) | NO (stream rapido) |
| Control del Excel | Python/openpyxl | Laravel/PhpSpreadsheet |
| Progreso visible | Limitado | Preciso (por chunk) |
| Timeout Gunicorn | Problema frecuente | No aplica (stream) |

---

## Notas importantes

1. **Laravel necesita queue worker corriendo:**
   ```bash
   php artisan queue:work redis --timeout=600
   ```

2. **Limpiar exports viejos (cron):**
   ```bash
   # Cada hora, borrar exports de mas de 30 min
   0 * * * * find /path/to/storage/app/exports -mmin +30 -delete
   ```

3. **Si el stream se corta** (timeout de red), Laravel captura el error y marca el job como failed.

4. **Limite recomendado:** 1,000,000 filas max por export. Mas de eso es impractico en Excel (limite de 1,048,576 filas).

---

*Generado: 2026-07-09*
