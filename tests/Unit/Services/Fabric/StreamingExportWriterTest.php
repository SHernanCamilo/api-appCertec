<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fabric;

use App\Services\Fabric\Export\ExportResult;
use App\Services\Fabric\Export\StreamingExportWriter;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifica el escritor de una sola pasada.
 *
 * Lo importante que se fija aquí:
 *   - Datasets pequeños producen xlsx; grandes producen CSV
 *   - NO se crea ningún archivo intermedio (el bug de rendimiento original)
 *   - Los ceros iniciales sobreviven al CSV
 *   - Las columnas con nombre numérico no rompen la escritura
 *   - El conteo de filas es exacto
 *
 * Extiende Tests\TestCase porque el writer usa el helper now() de Laravel.
 */
final class StreamingExportWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/export_writer_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function makeWriter(string $baseName = 'test_export'): StreamingExportWriter
    {
        return new StreamingExportWriter($this->tmpDir, $baseName, 'ca', 'VW_Portfolio_Test');
    }

    /**
     * Umbral real de la clase. Se lee por reflexión para que los tests no
     * queden desfasados cuando se ajusta la constante.
     */
    private function threshold(): int
    {
        return (new \ReflectionClass(StreamingExportWriter::class))
            ->getConstant('XLSX_THRESHOLD');
    }

    /** Cantidad mínima de filas que fuerza la salida en CSV. */
    private function rowsForCsv(int $extra = 1): int
    {
        return $this->threshold() + $extra;
    }

    // =========================================================================
    // Formato según tamaño
    // =========================================================================

    public function test_dataset_pequeno_genera_xlsx(): void
    {
        $writer = $this->makeWriter();

        $writer->writeRow(['Sede' => 'Neiva', 'Valor' => 100]);
        $writer->writeRow(['Sede' => 'Bogota', 'Valor' => 200]);

        $result = $writer->finish();

        $this->assertSame('xlsx', $result->format);
        $this->assertSame(2, $result->rows);
        $this->assertFileExists($result->path);
        $this->assertStringEndsWith('.xlsx', $result->filename);
    }

    public function test_dataset_grande_genera_csv(): void
    {
        $writer = $this->makeWriter();

        // 20.001 filas: una más que el umbral
        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow(['Id' => $i, 'Nombre' => "Fila {$i}"]);
        }

        $result = $writer->finish();

        $this->assertSame('csv', $result->format);
        $this->assertSame($this->rowsForCsv(), $result->rows);
        $this->assertFileExists($result->path);
        $this->assertStringEndsWith('.csv', $result->filename);
    }

    public function test_en_el_umbral_exacto_sigue_siendo_xlsx(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0, $n = $this->threshold(); $i < $n; $i++) {
            $writer->writeRow(['Id' => $i]);
        }

        $result = $writer->finish();

        $this->assertSame('xlsx', $result->format);
        $this->assertSame($this->threshold(), $result->rows);
    }

    // =========================================================================
    // NO archivo intermedio (la razón del refactor)
    // =========================================================================

    public function test_no_crea_archivo_intermedio(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0; $i < 25000; $i++) {
            $writer->writeRow(['Id' => $i, 'Dato' => 'x']);
        }

        $result = $writer->finish();

        $archivos = array_map('basename', glob($this->tmpDir . '/*') ?: []);

        $this->assertSame([$result->filename], $archivos, 'Solo debe existir el archivo final');
        $this->assertNotContains('data.tmp', $archivos);
        $this->assertNotContains('r2_data.gz', $archivos);
    }

    // =========================================================================
    // Integridad de los datos
    // =========================================================================

    public function test_csv_conserva_ceros_iniciales(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow(['Nro_Cuenta' => '036004835', 'Valor' => 100]);
        }

        $result   = $writer->finish();
        $contenido = (string) file_get_contents($result->path);

        $this->assertStringContainsString('="036004835"', $contenido);
        $this->assertStringNotContainsString(';36004835;', $contenido);
    }

    public function test_csv_incluye_bom_y_separador(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow(['A' => 1]);
        }

        $result = $writer->finish();
        $inicio = (string) file_get_contents($result->path, false, null, 0, 20);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $inicio, 'Falta el BOM UTF-8');
    }

    public function test_csv_escribe_la_cabecera_una_sola_vez(): void
    {
        $writer = $this->makeWriter();

        $total = $this->rowsForCsv(5);

        for ($i = 0; $i < $total; $i++) {
            $writer->writeRow(['Codigo' => "C{$i}", 'Cantidad' => $i]);
        }

        $result = $writer->finish();
        $lineas = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        // 1 cabecera + $total datos (ya no hay línea sep=)
        $this->assertCount($total + 1, $lineas);
        $this->assertSame(1, substr_count(implode("\n", $lineas), 'Cantidad'));
    }

    public function test_no_pierde_filas_al_cruzar_el_umbral(): void
    {
        $writer = $this->makeWriter();
        $total  = $this->rowsForCsv(50);

        for ($i = 0; $i < $total; $i++) {
            $writer->writeRow(['Id' => $i]);
        }

        $result = $writer->finish();
        $lineas = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        // Restamos cabecera
        $this->assertCount($total, array_slice($lineas, 1));
        $this->assertSame($total, $result->rows);
    }

    public function test_respeta_el_orden_de_las_columnas_en_filas_desalineadas(): void
    {
        $writer = $this->makeWriter();

        // Fabric puede devolver las claves en otro orden en filas posteriores
        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow($i === 0
                ? ['A' => 'a1', 'B' => 'b1']
                : ['B' => "b{$i}", 'A' => "a{$i}"]);
        }

        $result = $writer->finish();
        $lineas = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        // La columna A siempre va primero, según la cabecera
        $this->assertSame('a1,b1', $lineas[1]);
        $this->assertSame('a2,b2', $lineas[3]);
    }

    public function test_rellena_columnas_ausentes(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow($i === 0 ? ['A' => 1, 'B' => 2] : ['A' => 1]);
        }

        $result = $writer->finish();
        $lineas = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $this->assertSame('1,', $lineas[2], 'La columna ausente debe quedar vacía, no desplazar');
    }

    public function test_limpia_saltos_de_linea_para_no_romper_el_csv(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow(['Descripcion' => "LINEA1\nLINEA2", 'Id' => $i]);
        }

        $result = $writer->finish();
        $lineas = file($result->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        $this->assertCount($this->rowsForCsv() + 1, $lineas, 'Los saltos internos no deben crear filas extra');
    }

    // =========================================================================
    // Columnas con nombre numérico (vistas pivot)
    // =========================================================================

    public function test_soporta_columnas_con_nombre_numerico(): void
    {
        $writer = $this->makeWriter();

        // Reproduce VW_Inventory_AlmacenesPivot_315_Cmi
        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow(['315' => 1, '051' => 54, 'Producto' => 'ALOPURINOL']);
        }

        $result = $writer->finish();

        $this->assertSame($this->rowsForCsv(), $result->rows);
        $this->assertFileExists($result->path);

        $cabecera = (string) file_get_contents($result->path, false, null, 0, 100);
        $this->assertStringContainsString('315', $cabecera);
        $this->assertStringContainsString('051', $cabecera);
    }

    // =========================================================================
    // Casos límite
    // =========================================================================

    public function test_dataset_vacio_devuelve_resultado_vacio(): void
    {
        $result = $this->makeWriter()->finish();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->rows);
        $this->assertSame('', $result->path);
    }

    public function test_no_permite_escribir_despues_de_finalizar(): void
    {
        $writer = $this->makeWriter();
        $writer->writeRow(['A' => 1]);
        $writer->finish();

        $this->expectException(RuntimeException::class);
        $writer->writeRow(['A' => 2]);
    }

    public function test_no_permite_finalizar_dos_veces(): void
    {
        $writer = $this->makeWriter();
        $writer->writeRow(['A' => 1]);
        $writer->finish();

        $this->expectException(RuntimeException::class);
        $writer->finish();
    }

    public function test_abort_limpia_el_csv_a_medio_escribir(): void
    {
        $writer = $this->makeWriter();

        for ($i = 0, $n = $this->rowsForCsv(); $i < $n; $i++) {
            $writer->writeRow(['Id' => $i]);
        }

        $writer->abort();

        $this->assertSame([], glob($this->tmpDir . '/*.csv') ?: []);
    }

    public function test_crea_el_directorio_si_no_existe(): void
    {
        $nested = $this->tmpDir . '/nivel1/nivel2';
        $writer = new StreamingExportWriter($nested, 'x', 'ca', 'VW_Test');

        $writer->writeRow(['A' => 1]);
        $result = $writer->finish();

        $this->assertFileExists($result->path);

        @unlink($result->path);
        @rmdir($nested);
        @rmdir($this->tmpDir . '/nivel1');
    }

    // =========================================================================
    // Progreso
    // =========================================================================

    public function test_reporta_progreso_cada_50k_filas(): void
    {
        $writer     = $this->makeWriter();
        $reportados = [];

        $writer->onProgress(static function (int $rows) use (&$reportados): void {
            $reportados[] = $rows;
        });

        for ($i = 0; $i < 100000; $i++) {
            $writer->writeRow(['Id' => $i]);
        }
        $writer->finish();

        $this->assertSame([50000, 100000], $reportados);
    }

    public function test_no_reporta_progreso_en_datasets_pequenos(): void
    {
        $writer     = $this->makeWriter();
        $reportados = [];

        $writer->onProgress(static function (int $rows) use (&$reportados): void {
            $reportados[] = $rows;
        });

        $writer->writeRow(['A' => 1]);
        $writer->finish();

        $this->assertSame([], $reportados);
    }

    public function test_rowcount_refleja_el_avance(): void
    {
        $writer = $this->makeWriter();

        $this->assertSame(0, $writer->rowCount());

        $writer->writeRow(['A' => 1]);
        $writer->writeRow(['A' => 2]);

        $this->assertSame(2, $writer->rowCount());
    }

    // =========================================================================
    // ExportResult
    // =========================================================================

    public function test_result_reporta_tamano_legible(): void
    {
        $result = new ExportResult('/tmp/x.csv', 'x.csv', 'csv', 100, 1536);

        $this->assertSame('1.5 KB', $result->humanSize());
    }

    public function test_result_reporta_megabytes(): void
    {
        $result = new ExportResult('/tmp/x.csv', 'x.csv', 'csv', 100, 5 * 1024 * 1024);

        $this->assertSame('5 MB', $result->humanSize());
    }

    // =========================================================================
    // fromCsvFile — ruta de R2/DuckDB
    // =========================================================================

    /**
     * Regresión: el post-procesamiento con PhpSpreadsheet leía solo las primeras
     * 50 filas (para estimar anchos) y luego guardaba sobre el mismo archivo,
     * borrando el resto del dataset. Todo export salía con 50 filas.
     */
    public function test_from_csv_file_conserva_todas_las_filas(): void
    {
        $total   = 500;
        $csvPath = $this->tmpDir . '/origen.csv';

        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0775, true);
        }

        $handle = fopen($csvPath, 'w');
        fwrite($handle, "Id;Nombre;Valor\r\n");
        for ($i = 1; $i <= $total; $i++) {
            fwrite($handle, "{$i};FILA {$i};100\r\n");
        }
        fclose($handle);

        $result = StreamingExportWriter::fromCsvFile(
            $csvPath,
            $this->tmpDir,
            'export_completo',
            'dt',
            'VW_Test'
        );

        $this->assertSame('xlsx', $result->format);
        $this->assertSame($total, $result->rows, 'El xlsx debe conservar todas las filas');

        // Verificar en el archivo real, no solo en el contador
        $filasLeidas = $this->contarFilasXlsx($result->path);
        $this->assertSame(
            $total + 1,
            $filasLeidas,
            'El xlsx en disco debe tener las filas de datos + la cabecera'
        );

        @unlink($result->path);
        $this->limpiarTempOpenSpout();
    }

    /**
     * Regresión: una llave numérica de 37 dígitos se casteaba a float y salía
     * como 6,00621E+36; si excedía el rango del double salía como INF.
     */
    public function test_from_csv_file_no_corrompe_numeros_largos(): void
    {
        $llave   = '6006205000000000000000000000000000001';
        $csvPath = $this->tmpDir . '/origen.csv';

        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0775, true);
        }

        file_put_contents($csvPath, "Llave;Identificacion;Valor\r\n{$llave};036004835;1500\r\n");

        $result = StreamingExportWriter::fromCsvFile(
            $csvPath,
            $this->tmpDir,
            'export_llaves',
            'dt',
            'VW_Test'
        );

        $primeraFila = $this->primeraFilaDatosXlsx($result->path);

        $this->assertSame($llave, (string) $primeraFila[0], 'La llave larga debe salir íntegra');
        $this->assertSame('036004835', (string) $primeraFila[1], 'El cero inicial debe conservarse');
        $this->assertSame(1500.0, (float) $primeraFila[2], 'Los números normales siguen siendo números');

        @unlink($result->path);
        $this->limpiarTempOpenSpout();
    }

    private function contarFilasXlsx(string $path): int
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);

        $filas = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $filas++;
            }
            break;
        }
        $reader->close();

        return $filas;
    }

    /**
     * @return list<mixed>
     */
    private function primeraFilaDatosXlsx(string $path): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);

        $fila  = [];
        $index = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $index++;
                if ($index === 2) {
                    $fila = $row->toArray();
                    break;
                }
            }
            break;
        }
        $reader->close();

        return $fila;
    }

    private function limpiarTempOpenSpout(): void
    {
        $temp = $this->tmpDir . '/openspout_temp';
        if (is_dir($temp)) {
            foreach (glob($temp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($temp);
        }
    }
}
