<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Genera archivos Excel (.xlsx) con la plantilla corporativa JadeOne.
 *
 * Estilo:
 *   - Header corporativo con nombre del reporte y fecha
 *   - Encabezados de columna con fondo azul oscuro y texto blanco
 *   - Filas alternas con fondo gris claro
 *   - Auto-ajuste de columnas
 *   - Formato numérico y de fecha aplicado según tipo de dato
 *   - Footer con total de registros
 */
class FabricExcelGenerator
{
    // Colores corporativos JadeOne
    private const COLOR_HEADER_BG   = '1B3A5C'; // Azul oscuro
    private const COLOR_HEADER_TEXT = 'FFFFFF'; // Blanco
    private const COLOR_ROW_ALT     = 'F2F6FA'; // Gris azulado claro
    private const COLOR_TITLE_BG    = '0D6EFD'; // Azul primario
    private const COLOR_TITLE_TEXT  = 'FFFFFF';

    private Spreadsheet $spreadsheet;
    private string $schema;
    private string $view;
    private array $filters;

    public function __construct(string $schema, string $view, array $filters = [])
    {
        $this->schema = strtoupper($schema);
        $this->view   = $view;
        $this->filters = $filters;
        $this->spreadsheet = new Spreadsheet();
    }

    /**
     * Genera el Excel a partir de un array de filas.
     *
     * @param  array  $rows      Array de filas (cada fila es un array asociativo)
     * @param  string $filePath  Ruta absoluta donde guardar el archivo
     * @return array{rows: int, columns: int, file_size: int}
     */
    public function generate(array $rows, string $filePath): array
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->view, 0, 31)); // Excel limita a 31 chars

        // Configuración general
        $this->spreadsheet->getProperties()
            ->setCreator('JadeOne - Medilaser')
            ->setTitle("{$this->schema} - {$this->view}")
            ->setDescription('Exportado desde JadeOne Fabric Viewer');

        if (empty($rows)) {
            $sheet->setCellValue('A1', 'Sin datos para exportar con los filtros aplicados.');
            $writer = new Xlsx($this->spreadsheet);
            $writer->save($filePath);
            return ['rows' => 0, 'columns' => 0, 'file_size' => filesize($filePath)];
        }

        $headers = array_keys($rows[0]);
        $colCount = count($headers);
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        // ─── Fila 1: Título del reporte ───────────────────────
        $currentRow = 1;
        $sheet->mergeCells("A{$currentRow}:{$lastCol}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", "JadeOne — {$this->schema}.{$this->view}");
        $sheet->getStyle("A{$currentRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => self::COLOR_TITLE_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::COLOR_TITLE_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(28);

        // ─── Fila 2: Metadata (fecha, filtros, registros) ─────
        $currentRow = 2;
        $filterStr = empty($this->filters)
            ? 'Sin filtros'
            : implode(' | ', array_map(fn($k, $v) => "{$k}: {$v}", array_keys($this->filters), $this->filters));
        $meta = "Exportado: " . now()->format('d/m/Y H:i') . " | Registros: " . number_format(count($rows)) . " | Filtros: {$filterStr}";

        $sheet->mergeCells("A{$currentRow}:{$lastCol}{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", $meta);
        $sheet->getStyle("A{$currentRow}")->applyFromArray([
            'font' => ['size' => 9, 'italic' => true, 'color' => ['argb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(18);

        // ─── Fila 3: vacía (separador) ────────────────────────
        $currentRow = 3;

        // ─── Fila 4: Encabezados de columnas ──────────────────
        $currentRow = 4;
        foreach ($headers as $colIndex => $header) {
            $col = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue("{$col}{$currentRow}", $header);
        }

        $headerRange = "A{$currentRow}:{$lastCol}{$currentRow}";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::COLOR_HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::COLOR_HEADER_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(22);
        $sheet->setAutoFilter($headerRange);

        // ─── Filas de datos (bulk insert — mucho más rápido) ──
        $dataStartRow = $currentRow + 1;

        // Convertir rows asociativos a array indexado para fromArray()
        $dataMatrix = array_map(fn($row) => array_map(
            fn($h) => $row[$h] ?? null,
            $headers
        ), $rows);

        $sheet->fromArray($dataMatrix, null, "A{$dataStartRow}");

        $lastDataRow = $dataStartRow + count($rows) - 1;

        // Estilo de fuente solo si hay menos de 20K filas (para no ralentizar exports grandes)
        if (count($rows) <= 20000) {
            $dataRange = "A{$dataStartRow}:{$lastCol}{$lastDataRow}";
            $sheet->getStyle($dataRange)->getFont()->setSize(9);
        }

        // ─── Ajustar ancho de columnas (estimado, no auto-size) ──
        // AutoSize es lento con muchas filas. Usamos ancho estimado.
        foreach ($headers as $colIndex => $header) {
            $col = Coordinate::stringFromColumnIndex($colIndex + 1);
            // Estimar ancho: mínimo 12, máximo 40, basado en nombre de columna
            $width = max(12, min(40, strlen($header) + 4));
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // ─── Congelar paneles (header siempre visible) ────────
        $sheet->freezePane("A{$dataStartRow}");

        // ─── Guardar archivo ──────────────────────────────────
        $writer = new Xlsx($this->spreadsheet);
        $writer->save($filePath);

        return [
            'rows'      => count($rows),
            'columns'   => $colCount,
            'file_size' => filesize($filePath),
        ];
    }
}
