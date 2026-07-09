<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Export via streaming desde Graph-Fabric.
 *
 * Flujo:
 *   1. Frontend llama POST /api/fabric/viewer/export/start → recibe job_id
 *   2. Este job consume el stream de Python chunk por chunk (50K filas/chunk)
 *   3. Cada chunk se descomprime (gzip→NDJSON) y se acumula
 *   4. Al terminar, genera un CSV comprimido o Excel
 *   5. Frontend descarga con GET /export/download/{job_id}
 *
 * Ventaja: Graph-Fabric queda libre para atender otros usuarios.
 * El streaming es rápido (solo envía datos crudos), Laravel arma el archivo.
 */
final class FabricStreamExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600; // 10 min max

    private const STATUS_PENDING    = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED  = 'completed';
    private const STATUS_FAILED     = 'failed';

    public function __construct(
        private readonly string $jobId,
        private readonly int    $userId,
        private readonly string $schema,
        private readonly string $view,
        private readonly array  $options,
    ) {}

    public function handle(): void
    {
        // Excel con 150K+ filas necesita RAM para PhpSpreadsheet (genera XML interno)
        ini_set('memory_limit', '1G');
        set_time_limit(0); // Sin límite de tiempo (el job tiene su propio timeout de 600s)

        $this->updateStatus(self::STATUS_PROCESSING, null, ['progress' => 0, 'rows' => 0]);

        try {
            $user = User::findOrFail($this->userId);

            // Estrategia: escribir CSV directo a disco mientras consumimos datos.
            // Nunca acumulamos todas las filas en RAM.
            $this->exportDirectToCsv($user);

        } catch (\Throwable $e) {
            $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
            Log::error('FabricStreamExportJob [ERROR]', [
                'job_id' => $this->jobId,
                'schema' => $this->schema,
                'view'   => $this->view,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Exporta datos directo a CSV sin acumular en RAM.
     * Lee página por página de la API Python (5K filas/page) y escribe al archivo.
     */
    private function exportDirectToCsv(User $user): void
    {
        $url     = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token   = env('TOKEN_ADMIN', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        $maxRows = min((int)($this->options['max_rows'] ?? 500000), 1000000);
        $limit   = 5000;
        $offset  = 0;
        $totalRows = 0;
        $headers = [];

        // Preparar archivo Excel
        $filename = "{$this->schema}_{$this->view}_" . date('Ymd_His') . '.xlsx';
        $dir      = storage_path("app/fabric_exports/{$this->jobId}");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filePath = "{$dir}/{$filename}";

        // Crear spreadsheet con header corporativo
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->view, 0, 31));

        $spreadsheet->getProperties()
            ->setCreator('JadeOne - Medilaser')
            ->setTitle("{$this->schema} - {$this->view}");

        // Fila 1: Título
        $sheet->setCellValue('A1', "JadeOne — {$this->schema}.{$this->view}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getRowDimension(1)->setRowHeight(20);

        // Fila 2: Metadata
        $filterStr = !empty($this->options['filters'])
            ? implode(' | ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($this->options['filters']), $this->options['filters']))
            : 'Sin filtros';
        $sheet->setCellValue('A2', "Exportado: " . now()->format('d/m/Y H:i') . " | Filtros: {$filterStr}");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        // Fila 3: vacía (separador)
        $dataStartRow = 4; // Los headers van en fila 4, datos desde fila 5

        $payload = [
            'token'       => $token,
            'groups'      => $gateway->getGruposBd($user),
            'department'  => $gateway->getDepartamento($user),
            'user_email'  => $user->email,
            'user_name'   => $user->name ?? $user->email,
            'schema_name' => $this->schema,
            'view'        => $this->view,
            'filters'     => $gateway->normalizeFiltersPublic($this->options['filters'] ?? []),
            'columns'     => $this->options['columns'] ?? [],
            'sort_col'    => $this->options['sort_col'] ?? '',
            'sort_dir'    => $this->options['sort_dir'] ?? 'asc',
            'skip_count'  => true,
        ];

        // Paginar: leer 5K filas por request, escribir al Excel
        while ($offset < $maxRows) {
            $payload['limit']  = $limit;
            $payload['offset'] = $offset;

            $response = Http::timeout(130)
                ->connectTimeout(10)
                ->acceptJson()
                ->post($url . '/api/data/dynamic', $payload);

            if ($response->failed()) {
                $body = $response->json();
                if ($response->status() === 422 && ($body['error'] ?? '') === 'filters_required') {
                    $this->updateStatus(self::STATUS_FAILED, $body['message'] ?? 'Vista requiere filtros.');
                    return;
                }
                throw new \RuntimeException(
                    "Graph-Fabric respondió HTTP {$response->status()}: " . substr($response->body(), 0, 200)
                );
            }

            $data  = $response->json();
            $items = $data['items'] ?? [];

            if (empty($items)) {
                break;
            }

            // Escribir headers (solo la primera vez)
            if (empty($headers)) {
                $headers = array_keys($items[0]);
                $colCount = count($headers);

                // Escribir encabezados en fila 4
                foreach ($headers as $colIdx => $header) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                    $sheet->setCellValue("{$col}{$dataStartRow}", $header);
                }

                // Estilo de encabezados
                $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
                $headerRange = "A{$dataStartRow}:{$lastCol}{$dataStartRow}";
                $sheet->getStyle($headerRange)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFF']],
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => '1B3A5C']],
                ]);
                $sheet->setAutoFilter($headerRange);
                $sheet->freezePane('A' . ($dataStartRow + 1));

                // Merge título
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->mergeCells("A2:{$lastCol}2");
            }

            // Escribir filas de datos usando fromArray (mucho más rápido que celda por celda)
            $excelRow = $dataStartRow + 1 + $totalRows;
            $dataMatrix = [];
            foreach ($items as $row) {
                $rowData = [];
                foreach ($headers as $h) {
                    $val = $row[$h] ?? '';
                    // Limpiar saltos de línea que rompen la celda
                    if (is_string($val)) {
                        $val = str_replace(["\r\n", "\r", "\n"], ' ', $val);
                    }
                    $rowData[] = $val;
                }
                $dataMatrix[] = $rowData;
                $totalRows++;
            }
            $sheet->fromArray($dataMatrix, null, "A{$excelRow}");

            // Liberar memoria
            unset($items, $data, $dataMatrix);

            $offset += $limit;

            // Actualizar progreso
            $progress = min(92, intval($totalRows / $maxRows * 92));
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => $progress,
                'rows'     => $totalRows,
                'message'  => "Exportando... ({$totalRows} filas)",
            ]);

            // Si no hay más páginas
            $pageInfo = $response->json()['page_info'] ?? [];
            if (!($pageInfo['has_next'] ?? false)) {
                break;
            }
        }

        // Si no hubo datos
        if ($totalRows === 0) {
            $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                'rows' => 0, 'progress' => 100,
            ]);
            return;
        }

        // Ajustar anchos de columna (estimado, sin autoSize que es lento)
        foreach ($headers as $colIdx => $header) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
            $sheet->getColumnDimension($col)->setWidth(max(12, min(35, strlen($header) + 4)));
        }

        // Actualizar metadata con total real
        $sheet->setCellValue('A2', "Exportado: " . now()->format('d/m/Y H:i') . " | Registros: " . number_format($totalRows) . " | Filtros: {$filterStr}");

        // Guardar archivo
        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 95,
            'rows'     => $totalRows,
            'message'  => 'Generando archivo Excel...',
        ]);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($filePath);

        // Liberar memoria del spreadsheet
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);

        $fileSize    = filesize($filePath);
        $storagePath = "fabric_exports/{$this->jobId}/{$filename}";

        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'progress'        => 100,
            'rows'            => $totalRows,
            'filename'        => $filename,
            'file_path'       => $storagePath,
            'file_size'       => $fileSize,
            'file_size_human' => $this->humanFileSize($fileSize),
            'format'          => 'xlsx',
        ]);

        Log::info('FabricStreamExportJob: Excel generado', [
            'job_id'   => $this->jobId,
            'rows'     => $totalRows,
            'size'     => $fileSize,
            'filename' => $filename,
        ]);
    }

    // =========================================================================
    // STATUS / TRACKING
    // =========================================================================

    private function updateStatus(string $status, ?string $message = null, ?array $meta = null): void
    {
        $current = Cache::get("fabric_export:{$this->jobId}") ?? [];

        $data = array_merge($current, [
            'status'     => $status,
            'updated_at' => now()->toIso8601String(),
        ]);

        if ($message !== null) {
            $data['message'] = $message;
        }
        if ($meta !== null) {
            $data = array_merge($data, $meta);
        }

        Cache::put("fabric_export:{$this->jobId}", $data, 1800); // 30 min TTL
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
    }

    /**
     * Despacha el job y retorna el job_id.
     */
    public static function dispatch_and_track(
        int    $userId,
        string $schema,
        string $view,
        array  $options
    ): string {
        $jobId = 'exp_stream_' . bin2hex(random_bytes(12));

        Cache::put("fabric_export:{$jobId}", [
            'status'     => self::STATUS_PENDING,
            'progress'   => 0,
            'rows'       => 0,
            'schema'     => $schema,
            'view'       => $view,
            'user_id'    => $userId,
            'format'     => 'xlsx',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 1800);

        self::dispatch($jobId, $userId, $schema, $view, $options);

        return $jobId;
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
