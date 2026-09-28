<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporte de consumo de vistas BI a partir de la auditoría (bi_vista_access_logs).
 *
 * Genera un Excel con varias hojas:
 *   - Resumen            → totales generales del período.
 *   - Usuarios           → un usuario por fila (SIN duplicar): correo, nombre,
 *                          empresa, total de consultas, primera y última.
 *   - Vistas más usadas  → ranking de vistas por número de consultas y usuarios.
 *   - <Mes YYYY-MM>       → una hoja por mes con el detalle de ese mes.
 *
 * USO:
 *   php artisan bi:reporte-consumo
 *   php artisan bi:reporte-consumo --desde=2026-01-01 --hasta=2026-09-30
 *   php artisan bi:reporte-consumo --accion=consulta   (solo consultas)
 *
 * El archivo se guarda en storage/app/reportes/ y la ruta se imprime al final.
 */
class ReporteConsumoVistasCommand extends Command
{
    protected $signature = 'bi:reporte-consumo
        {--desde= : Fecha inicial YYYY-MM-DD (opcional)}
        {--hasta= : Fecha final YYYY-MM-DD (opcional)}
        {--accion= : Filtrar por accion: consulta|exportacion_inicio|exportacion_descarga|exportacion_sync (opcional)}';

    protected $description = 'Genera un Excel con el consumo de vistas BI (usuarios, vistas y libro por mes) desde la auditoría.';

    private const TABLA = 'bi_vista_access_logs';

    public function handle(): int
    {
        if (!Schema::hasTable(self::TABLA)) {
            $this->error('No existe la tabla de auditoría ' . self::TABLA . '.');
            return self::FAILURE;
        }

        $desde  = $this->option('desde');
        $hasta  = $this->option('hasta');
        $accion = $this->option('accion');

        $this->info('Generando reporte de consumo de vistas BI...');
        if ($desde || $hasta) {
            $this->line('  Período: ' . ($desde ?: 'inicio') . ' → ' . ($hasta ?: 'hoy'));
        }
        if ($accion) {
            $this->line('  Acción : ' . $accion);
        }

        // Filtro base reutilizable.
        $base = fn () => $this->baseQuery($desde, $hasta, $accion);

        $totalRegistros = (clone $base())->count();
        if ($totalRegistros === 0) {
            $this->warn('No hay registros de auditoría con esos filtros. No se genera archivo.');
            return self::SUCCESS;
        }
        $this->line("  Registros a procesar: {$totalRegistros}");

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // se crean las hojas manualmente

        $this->hojaResumen($spreadsheet, $base, $desde, $hasta, $accion);
        $this->hojaUsuarios($spreadsheet, $base);
        $this->hojaVistas($spreadsheet, $base);
        $this->hojasPorMes($spreadsheet, $base);

        $spreadsheet->setActiveSheetIndex(0);

        // Guardar en storage/app/reportes.
        $dir = storage_path('app/reportes');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $nombre = 'consumo_vistas_bi_' . now()->format('Ymd_His') . '.xlsx';
        $ruta   = $dir . DIRECTORY_SEPARATOR . $nombre;

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($ruta);
        $spreadsheet->disconnectWorksheets();

        $this->newLine();
        $this->info('Reporte generado correctamente.');
        $this->line('  Archivo: ' . $ruta);
        $this->line('  Tamaño : ' . number_format(filesize($ruta) / 1024, 1) . ' KB');

        return self::SUCCESS;
    }

    // =========================================================================
    // Consulta base
    // =========================================================================

    private function baseQuery(?string $desde, ?string $hasta, ?string $accion)
    {
        $q = DB::table(self::TABLA);

        if ($desde) {
            $q->where('accessed_at', '>=', $desde . ' 00:00:00');
        }
        if ($hasta) {
            $q->where('accessed_at', '<=', $hasta . ' 23:59:59');
        }
        if ($accion) {
            $q->where('accion', $accion);
        }

        return $q;
    }

    // =========================================================================
    // Hoja: Resumen
    // =========================================================================

    private function hojaResumen($spreadsheet, callable $base, ?string $desde, ?string $hasta, ?string $accion): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen');

        $totalConsultas   = (clone $base())->count();
        $usuariosUnicos   = (clone $base())->distinct()->count('user_id');
        $vistasUnicas     = (clone $base())->select(DB::raw("CONCAT(schema_name,'.',view_name) as v"))->distinct()->count(DB::raw("CONCAT(schema_name,'.',view_name)"));
        $empresasUnicas   = (clone $base())->distinct()->count('empresa_id');
        $primera          = (clone $base())->min('accessed_at');
        $ultima           = (clone $base())->max('accessed_at');

        $sheet->setCellValue('A1', 'REPORTE DE CONSUMO DE VISTAS BI');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $filas = [
            ['Generado', now()->format('Y-m-d H:i:s')],
            ['Período desde', $desde ?: '(todo)'],
            ['Período hasta', $hasta ?: '(hoy)'],
            ['Acción filtrada', $accion ?: 'todas'],
            ['', ''],
            ['Total de accesos', $totalConsultas],
            ['Usuarios únicos', $usuariosUnicos],
            ['Vistas únicas consultadas', $vistasUnicas],
            ['Empresas', $empresasUnicas],
            ['Primer acceso', $primera],
            ['Último acceso', $ultima],
        ];

        $r = 3;
        foreach ($filas as [$k, $v]) {
            $sheet->setCellValue("A{$r}", $k);
            $sheet->setCellValue("B{$r}", $v);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(30);
    }

    // =========================================================================
    // Hoja: Usuarios (uno por fila, sin duplicar)
    // =========================================================================

    private function hojaUsuarios($spreadsheet, callable $base): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Usuarios');

        $encabezados = ['Usuario', 'Correo', 'Empresa', 'Total accesos', 'Vistas distintas', 'Primera consulta', 'Última consulta'];
        $this->escribirEncabezado($sheet, $encabezados);

        // Agrupado por usuario: sin duplicar, con conteos y fechas.
        $usuarios = (clone $base())
            ->select(
                'user_id',
                DB::raw('MAX(user_name) as user_name'),
                DB::raw('MAX(user_email) as user_email'),
                DB::raw('MAX(empresa_nombre) as empresa_nombre'),
                DB::raw('COUNT(*) as total'),
                DB::raw("COUNT(DISTINCT CONCAT(schema_name,'.',view_name)) as vistas_distintas"),
                DB::raw('MIN(accessed_at) as primera'),
                DB::raw('MAX(accessed_at) as ultima')
            )
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get();

        $r = 2;
        foreach ($usuarios as $u) {
            $sheet->setCellValue("A{$r}", $u->user_name ?? '(sin nombre)');
            $sheet->setCellValue("B{$r}", $u->user_email ?? '(sin correo)');
            $sheet->setCellValue("C{$r}", $u->empresa_nombre ?? '');
            $sheet->setCellValue("D{$r}", (int) $u->total);
            $sheet->setCellValue("E{$r}", (int) $u->vistas_distintas);
            $sheet->setCellValue("F{$r}", (string) $u->primera);
            $sheet->setCellValue("G{$r}", (string) $u->ultima);
            $r++;
        }

        $this->autoAncho($sheet, count($encabezados));
    }

    // =========================================================================
    // Hoja: Vistas más consultadas
    // =========================================================================

    private function hojaVistas($spreadsheet, callable $base): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Vistas más usadas');

        $encabezados = ['Esquema', 'Vista', 'Total accesos', 'Usuarios distintos', 'Filas servidas', 'Primera', 'Última'];
        $this->escribirEncabezado($sheet, $encabezados);

        $vistas = (clone $base())
            ->select(
                'schema_name',
                'view_name',
                DB::raw('COUNT(*) as total'),
                DB::raw('COUNT(DISTINCT user_id) as usuarios'),
                DB::raw('SUM(rows_returned) as filas'),
                DB::raw('MIN(accessed_at) as primera'),
                DB::raw('MAX(accessed_at) as ultima')
            )
            ->groupBy('schema_name', 'view_name')
            ->orderByDesc('total')
            ->get();

        $r = 2;
        foreach ($vistas as $v) {
            $sheet->setCellValue("A{$r}", $v->schema_name);
            $sheet->setCellValue("B{$r}", $v->view_name);
            $sheet->setCellValue("C{$r}", (int) $v->total);
            $sheet->setCellValue("D{$r}", (int) $v->usuarios);
            $sheet->setCellValue("E{$r}", (int) $v->filas);
            $sheet->setCellValue("F{$r}", (string) $v->primera);
            $sheet->setCellValue("G{$r}", (string) $v->ultima);
            $r++;
        }

        $this->autoAncho($sheet, count($encabezados));
    }

    // =========================================================================
    // Hojas: un libro (hoja) por mes
    // =========================================================================

    private function hojasPorMes($spreadsheet, callable $base): void
    {
        // Meses presentes en los datos.
        $meses = (clone $base())
            ->select(DB::raw("DATE_FORMAT(accessed_at, '%Y-%m') as mes"))
            ->distinct()
            ->orderBy('mes')
            ->pluck('mes');

        foreach ($meses as $mes) {
            // El resumen por usuario dentro de ese mes (sin duplicar).
            $filas = (clone $base())
                ->whereRaw("DATE_FORMAT(accessed_at, '%Y-%m') = ?", [$mes])
                ->select(
                    DB::raw('MAX(user_name) as user_name'),
                    DB::raw('MAX(user_email) as user_email'),
                    DB::raw('MAX(empresa_nombre) as empresa_nombre'),
                    DB::raw('COUNT(*) as total'),
                    DB::raw("COUNT(DISTINCT CONCAT(schema_name,'.',view_name)) as vistas_distintas"),
                    DB::raw('MIN(accessed_at) as primera'),
                    DB::raw('MAX(accessed_at) as ultima')
                )
                ->groupBy('user_id')
                ->orderByDesc('total')
                ->get();

            // El título de la hoja no puede pasar de 31 chars ni tener ciertos símbolos.
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(substr('Mes ' . $mes, 0, 31));

            $encabezados = ['Usuario', 'Correo', 'Empresa', 'Accesos', 'Vistas distintas', 'Primera', 'Última'];
            $this->escribirEncabezado($sheet, $encabezados);

            $r = 2;
            foreach ($filas as $f) {
                $sheet->setCellValue("A{$r}", $f->user_name ?? '(sin nombre)');
                $sheet->setCellValue("B{$r}", $f->user_email ?? '(sin correo)');
                $sheet->setCellValue("C{$r}", $f->empresa_nombre ?? '');
                $sheet->setCellValue("D{$r}", (int) $f->total);
                $sheet->setCellValue("E{$r}", (int) $f->vistas_distintas);
                $sheet->setCellValue("F{$r}", (string) $f->primera);
                $sheet->setCellValue("G{$r}", (string) $f->ultima);
                $r++;
            }

            $this->autoAncho($sheet, count($encabezados));
        }
    }

    // =========================================================================
    // Helpers de estilo
    // =========================================================================

    private function escribirEncabezado(Worksheet $sheet, array $encabezados): void
    {
        $col = 1;
        foreach ($encabezados as $texto) {
            $letra = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letra}1", $texto);
            $col++;
        }

        $ultimaCol = Coordinate::stringFromColumnIndex(count($encabezados));
        $rango = "A1:{$ultimaCol}1";
        $sheet->getStyle($rango)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($rango)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('217346'); // verde Excel
        $sheet->getStyle($rango)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');
    }

    private function autoAncho(Worksheet $sheet, int $numCols): void
    {
        for ($c = 1; $c <= $numCols; $c++) {
            $letra = Coordinate::stringFromColumnIndex($c);
            $sheet->getColumnDimension($letra)->setAutoSize(true);
        }
    }
}
