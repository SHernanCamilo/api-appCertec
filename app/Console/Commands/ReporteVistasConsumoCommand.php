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
 * Reporte de vistas más consultadas CRUZADO con su configuración de parquet.
 *
 * Objetivo: ajustar los carriles de generación de parquets. Responde:
 *   - ¿Cuáles vistas se consultan más y cuánto?
 *   - ¿Cada una tiene parquet configurado? ¿con qué intervalo/prioridad?
 *   - ¿Cuáles vistas MUY consultadas NO tienen parquet (candidatas a configurar)?
 *   - ¿Cuáles vistas con parquet casi no se usan (candidatas a bajar prioridad)?
 *
 * Hojas del Excel:
 *   1. Ranking de uso        → vista + accesos + config de parquet + recomendación
 *   2. Sin parquet (top uso) → vistas muy usadas SIN parquet configurado
 *   3. Parquet poco usado    → vistas configuradas con muy pocos/ningún acceso
 *   4. Resumen por esquema   → consumo agregado por esquema
 *
 * USO:
 *   php artisan bi:reporte-vistas-consumo
 *   php artisan bi:reporte-vistas-consumo --desde=2026-07-01 --hasta=2026-09-30
 *   php artisan bi:reporte-vistas-consumo --top=100
 */
class ReporteVistasConsumoCommand extends Command
{
    protected $signature = 'bi:reporte-vistas-consumo
        {--desde= : Fecha inicial YYYY-MM-DD (opcional)}
        {--hasta= : Fecha final YYYY-MM-DD (opcional)}
        {--top=0 : Limitar el ranking a las N vistas más usadas (0 = todas)}';

    protected $description = 'Excel de vistas más consultadas cruzado con su config de parquet, para ajustar los carriles.';

    private const TABLA = 'bi_vista_access_logs';

    public function handle(): int
    {
        if (!Schema::hasTable(self::TABLA)) {
            $this->error('No existe la tabla de auditoría ' . self::TABLA . '.');
            return self::FAILURE;
        }

        $desde = $this->option('desde');
        $hasta = $this->option('hasta');
        $top   = (int) $this->option('top');

        $this->info('Generando reporte de vistas más consultadas (para ajustar carriles)...');
        if ($desde || $hasta) {
            $this->line('  Período: ' . ($desde ?: 'inicio') . ' → ' . ($hasta ?: 'hoy'));
        }

        // ── Consumo por vista desde la auditoría ────────────────────────────
        $q = DB::table(self::TABLA)
            ->select(
                'schema_name',
                'view_name',
                DB::raw('COUNT(*) as accesos'),
                DB::raw('COUNT(DISTINCT user_id) as usuarios'),
                DB::raw('SUM(rows_returned) as filas_servidas'),
                DB::raw('ROUND(AVG(elapsed_ms)) as prom_ms'),
                DB::raw('MAX(elapsed_ms) as max_ms'),
                DB::raw('MIN(accessed_at) as primera'),
                DB::raw('MAX(accessed_at) as ultima')
            )
            ->groupBy('schema_name', 'view_name')
            ->orderByDesc('accesos');

        if ($desde) $q->where('accessed_at', '>=', $desde . ' 00:00:00');
        if ($hasta) $q->where('accessed_at', '<=', $hasta . ' 23:59:59');

        $consumo = $q->get();

        if ($consumo->isEmpty()) {
            $this->warn('No hay registros de auditoría con esos filtros.');
            return self::SUCCESS;
        }

        // ── Config de parquet indexada por esquema.vista ────────────────────
        $configs = [];
        if (Schema::hasTable('bi_parquet_config')) {
            foreach (DB::table('bi_parquet_config')->get() as $c) {
                $clave = strtolower($c->schema_name) . '.' . $c->view_name;
                $configs[$clave] = $c;
            }
        }

        $this->line('  Vistas consultadas: ' . $consumo->count());
        $this->line('  Parquets configurados: ' . count($configs));

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $this->hojaRanking($spreadsheet, $consumo, $configs, $top);
        $this->hojaSinParquet($spreadsheet, $consumo, $configs);
        $this->hojaParquetPocoUsado($spreadsheet, $consumo, $configs);
        $this->hojaPorEsquema($spreadsheet, $consumo);

        $spreadsheet->setActiveSheetIndex(0);

        $dir = storage_path('app/reportes');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $ruta = $dir . DIRECTORY_SEPARATOR . 'vistas_consumo_carriles_' . now()->format('Ymd_His') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($ruta);
        $spreadsheet->disconnectWorksheets();

        $this->newLine();
        $this->info('Reporte generado.');
        $this->line('  Archivo: ' . $ruta);
        $this->line('  Tamaño : ' . number_format(filesize($ruta) / 1024, 1) . ' KB');

        return self::SUCCESS;
    }

    // =========================================================================
    // Hoja 1: Ranking de uso + config de parquet + recomendación
    // =========================================================================

    private function hojaRanking($spreadsheet, $consumo, array $configs, int $top): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Ranking de uso');

        $enc = ['#', 'Esquema', 'Vista', 'Accesos', 'Usuarios', 'Filas servidas',
                'Prom. ms', 'Máx. ms', 'Parquet', 'Intervalo (min)', 'Prioridad',
                'Última sync', 'Recomendación'];
        $this->encabezado($sheet, $enc);

        $r = 2; $i = 0;
        foreach ($consumo as $v) {
            $i++;
            if ($top > 0 && $i > $top) break;

            $clave = strtolower($v->schema_name) . '.' . $v->view_name;
            $cfg = $configs[$clave] ?? null;

            $tieneParquet = $cfg ? 'Sí' . ($cfg->enabled ? '' : ' (desactivado)') : 'NO';
            $intervalo = $cfg ? (int) $cfg->refresh_interval_min : '';
            $prioridad = $cfg ? $cfg->priority : '';
            $ultSync   = $cfg ? (string) $cfg->last_synced_at : '';

            $recom = $this->recomendacion((int) $v->accesos, (int) $v->usuarios, $cfg);

            $this->fila($sheet, $r, [
                $i, $v->schema_name, $v->view_name, (int) $v->accesos, (int) $v->usuarios,
                (int) $v->filas_servidas, (int) $v->prom_ms, (int) $v->max_ms,
                $tieneParquet, $intervalo, $prioridad, $ultSync, $recom,
            ]);
            $r++;
        }
        $this->autoAncho($sheet, count($enc));
    }

    // =========================================================================
    // Hoja 2: Vistas muy usadas SIN parquet (candidatas a configurar)
    // =========================================================================

    private function hojaSinParquet($spreadsheet, $consumo, array $configs): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Sin parquet (top uso)');

        $enc = ['Esquema', 'Vista', 'Accesos', 'Usuarios', 'Prom. ms', 'Última consulta'];
        $this->encabezado($sheet, $enc);

        $r = 2;
        foreach ($consumo as $v) {
            $clave = strtolower($v->schema_name) . '.' . $v->view_name;
            if (isset($configs[$clave])) continue; // ya tiene parquet
            // Solo las que valen la pena (>= 5 accesos)
            if ((int) $v->accesos < 5) continue;

            $this->fila($sheet, $r, [
                $v->schema_name, $v->view_name, (int) $v->accesos, (int) $v->usuarios,
                (int) $v->prom_ms, (string) $v->ultima,
            ]);
            $r++;
        }
        if ($r === 2) {
            $this->fila($sheet, 2, ['—', 'Todas las vistas muy usadas ya tienen parquet', '', '', '', '']);
        }
        $this->autoAncho($sheet, count($enc));
    }

    // =========================================================================
    // Hoja 3: Parquet configurado pero poco/nada usado (bajar prioridad)
    // =========================================================================

    private function hojaParquetPocoUsado($spreadsheet, $consumo, array $configs): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Parquet poco usado');

        $enc = ['Esquema', 'Vista', 'Accesos', 'Intervalo (min)', 'Prioridad', 'Observación'];
        $this->encabezado($sheet, $enc);

        // Accesos por clave para buscar rápido.
        $accesosPorClave = [];
        foreach ($consumo as $v) {
            $accesosPorClave[strtolower($v->schema_name) . '.' . $v->view_name] = (int) $v->accesos;
        }

        $r = 2;
        foreach ($configs as $clave => $cfg) {
            $accesos = $accesosPorClave[$clave] ?? 0;
            if ($accesos >= 10) continue; // se usa lo suficiente, no es candidata

            [$esq, $vista] = array_pad(explode('.', $clave, 2), 2, '');
            $obs = $accesos === 0
                ? 'Nunca consultada en el período: evaluar desactivar el parquet'
                : 'Poco uso: considerar subir el intervalo o bajar prioridad';

            $this->fila($sheet, $r, [
                $esq, $vista, $accesos, (int) $cfg->refresh_interval_min, $cfg->priority, $obs,
            ]);
            $r++;
        }
        if ($r === 2) {
            $this->fila($sheet, 2, ['—', 'Todos los parquets configurados tienen uso', '', '', '', '']);
        }
        $this->autoAncho($sheet, count($enc));
    }

    // =========================================================================
    // Hoja 4: Resumen por esquema
    // =========================================================================

    private function hojaPorEsquema($spreadsheet, $consumo): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen por esquema');

        $enc = ['Esquema', 'Vistas distintas', 'Accesos totales', 'Filas servidas'];
        $this->encabezado($sheet, $enc);

        $agg = [];
        foreach ($consumo as $v) {
            $e = $v->schema_name;
            if (!isset($agg[$e])) $agg[$e] = ['vistas' => 0, 'accesos' => 0, 'filas' => 0];
            $agg[$e]['vistas']++;
            $agg[$e]['accesos'] += (int) $v->accesos;
            $agg[$e]['filas']   += (int) $v->filas_servidas;
        }
        uasort($agg, fn($a, $b) => $b['accesos'] <=> $a['accesos']);

        $r = 2;
        foreach ($agg as $esq => $d) {
            $this->fila($sheet, $r, [$esq, $d['vistas'], $d['accesos'], $d['filas']]);
            $r++;
        }
        $this->autoAncho($sheet, count($enc));
    }

    // =========================================================================
    // Lógica de recomendación para ajustar el carril
    // =========================================================================

    private function recomendacion(int $accesos, int $usuarios, $cfg): string
    {
        if (!$cfg) {
            if ($accesos >= 100) return 'ALTA demanda sin parquet: configurar parquet con prioridad operativo';
            if ($accesos >= 20)  return 'Uso medio sin parquet: evaluar configurar parquet';
            return 'Bajo uso sin parquet: servir en vivo está bien';
        }
        if (!$cfg->enabled) {
            return 'Parquet DESACTIVADO pero se consulta: evaluar reactivar';
        }
        if ($accesos >= 500) {
            return 'Muy alta demanda: prioridad alta, intervalo corto';
        }
        if ($accesos < 5) {
            return 'Parquet con poco uso: subir intervalo / bajar prioridad';
        }
        return 'Configuración acorde al uso';
    }

    // =========================================================================
    // Helpers de estilo
    // =========================================================================

    private function encabezado(Worksheet $sheet, array $enc): void
    {
        $col = 1;
        foreach ($enc as $t) {
            $letra = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letra}1", $t);
            $col++;
        }
        $ultima = Coordinate::stringFromColumnIndex(count($enc));
        $sheet->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$ultima}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('217346');
        $sheet->getStyle("A1:{$ultima}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');
    }

    private function fila(Worksheet $sheet, int $r, array $valores): void
    {
        $col = 1;
        foreach ($valores as $v) {
            $letra = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letra}{$r}", $v);
            $col++;
        }
    }

    private function autoAncho(Worksheet $sheet, int $numCols): void
    {
        for ($c = 1; $c <= $numCols; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }
}
