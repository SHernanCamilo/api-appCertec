<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fabric;

use App\Services\Fabric\Export\SpoutXlsxWriter;
use Tests\TestCase;
use ZipArchive;

/**
 * Fija el comportamiento del escritor de xlsx sobre OpenSpout.
 *
 * Este writer reemplazó al generador de XML propio. La validación central de
 * cada test es la misma que hará Excel: abrir el .xlsx, sacar la hoja DEL ZIP y
 * parsear el XML. Validar solo el contenido no alcanzaba: los archivos que
 * fallaban en producción tenían XML "válido" suelto pero el ZIP no se podía leer.
 */
final class SpoutXlsxWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/spout_test_' . uniqid();
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @param list<array<string,mixed>>|list<string> $rows */
    private function writeGz(array $rows, bool $raw = false): string
    {
        $path = $this->tmpDir . '/data.ndjson.gz';
        $gz   = gzopen($path, 'wb1');

        foreach ($rows as $row) {
            $gz && gzwrite($gz, ($raw ? (string) $row : json_encode($row, JSON_UNESCAPED_UNICODE)) . "\n");
        }

        gzclose($gz);

        return $path;
    }

    /** @return list<list<mixed>> */
    private function readRows(string $xlsxPath, int $limit = 20): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($xlsxPath);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
                if (count($rows) >= $limit) {
                    break;
                }
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /**
     * Valida el .xlsx igual que Excel: lee la hoja DEL ZIP y parsea todo el XML.
     */
    private function assertXlsxIsReadable(string $xlsxPath): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsxPath) === true, 'El .xlsx debe abrir como ZIP');

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'] as $parte) {
            $this->assertNotFalse($zip->locateName($parte), "Falta la parte {$parte}");
        }

        $stream = $zip->getStream('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($stream, 'La hoja debe poder leerse del ZIP');

        $parser = xml_parser_create('UTF-8');
        $ok     = true;
        $detail = '';

        while (!feof($stream)) {
            $chunk = fread($stream, 262144);
            if (!xml_parse($parser, $chunk, feof($stream))) {
                $ok = false;
                $detail = 'línea ' . xml_get_current_line_number($parser)
                    . ' col ' . xml_get_current_column_number($parser)
                    . ': ' . xml_error_string(xml_get_error_code($parser));
                break;
            }
        }

        xml_parser_free($parser);
        fclose($stream);
        $zip->close();

        $this->assertTrue($ok, "El XML de la hoja debe parsear completo ({$detail})");
    }

    // =========================================================================
    // Estructura
    // =========================================================================

    public function test_genera_un_xlsx_legible_desde_el_zip(): void
    {
        $gz = $this->writeGz([
            ['Sede' => 'Neiva', 'Valor' => 100],
            ['Sede' => 'Tunja', 'Valor' => 200],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');

        $this->assertNotNull($result);
        $this->assertSame('xlsx', $result->format);
        $this->assertSame(2, $result->rows);
        $this->assertXlsxIsReadable($result->path);

        $rows = $this->readRows($result->path);
        $this->assertSame(['Sede', 'Valor'], $rows[0]);
        $this->assertSame('Neiva', $rows[1][0]);
    }

    public function test_con_titulo_agrega_portada_y_los_datos_bajan(): void
    {
        $gz = $this->writeGz([['Sede' => 'Neiva', 'Valor' => 100]]);

        $result = SpoutXlsxWriter::fromNdjsonGz(
            $gz,
            $this->tmpDir,
            'export',
            'VW_Test',
            'hg.VW_HC_Evoluciones'
        );

        $this->assertNotNull($result);
        $this->assertSame(1, $result->rows, 'La portada no cuenta como fila de datos');
        $this->assertXlsxIsReadable($result->path);

        $rows = $this->readRows($result->path);
        $this->assertStringContainsString('hg.VW_HC_Evoluciones', (string) $rows[0][0]);
        $this->assertStringContainsString('Exportado:', (string) $rows[1][0]);
        $this->assertSame(['Sede', 'Valor'], $rows[2]);
        $this->assertSame('Neiva', $rows[3][0]);
    }

    public function test_devuelve_null_si_no_hay_filas(): void
    {
        $this->assertNull(
            SpoutXlsxWriter::fromNdjsonGz($this->writeGz([]), $this->tmpDir, 'export', 'VW')
        );
    }

    public function test_devuelve_null_si_el_gz_no_existe(): void
    {
        $this->assertNull(
            SpoutXlsxWriter::fromNdjsonGz($this->tmpDir . '/nope.gz', $this->tmpDir, 'export', 'VW')
        );
    }

    public function test_no_deja_directorios_temporales(): void
    {
        $gz     = $this->writeGz([['A' => 1]]);
        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');

        $this->assertNotNull($result);
        $this->assertSame([], glob($this->tmpDir . '/spout_temp_*') ?: []);
    }

    // =========================================================================
    // Tipos de celda
    // =========================================================================

    public function test_conserva_ceros_iniciales(): void
    {
        $gz = $this->writeGz([
            ['Documento' => '0012000123', 'X' => 1],
            ['Documento' => '0009988776', 'X' => 2],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);
        $this->assertSame('0012000123', (string) $rows[1][0]);
    }

    public function test_las_fechas_salen_como_fecha_real_de_excel(): void
    {
        $gz = $this->writeGz([
            ['FechaIngreso' => '2026-08-28T14:35:09', 'FechaCorte' => '2026-08-28'],
            ['FechaIngreso' => '2026-01-15T08:00:00', 'FechaCorte' => '2026-01-15'],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][0]);
        $this->assertSame('2026-08-28 14:35:09', $rows[1][0]->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][1]);
        $this->assertSame('2026-08-28', $rows[1][1]->format('Y-m-d'));
    }

    public function test_los_numeros_son_numeros(): void
    {
        $gz = $this->writeGz([['Valor' => 1500.75], ['Valor' => 2300]]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);
        $this->assertIsNumeric($rows[1][0]);
        $this->assertSame(1500.75, (float) $rows[1][0]);
    }

    public function test_las_llaves_muy_largas_van_como_texto(): void
    {
        $llave = '6006205000000000000000000000000000001';
        $gz    = $this->writeGz([['Llave' => $llave], ['Llave' => $llave]]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $this->assertSame($llave, (string) $this->readRows($result->path)[1][0]);
    }

    // =========================================================================
    // Robustez con datos sucios — el motivo por el que existe este writer
    // =========================================================================

    /**
     * Regresión: U+FFFE es UTF-8 válido pero XML lo prohíbe. OpenSpout escapa
     * < > & pero NO filtra estos codepoints, así que el saneo es nuestro.
     */
    public function test_limpia_codepoints_prohibidos_sin_romper_el_archivo(): void
    {
        $gz = $this->writeGz([
            ['Nota' => "Dolor abdominal\u{FFFE} agudo"],
            ['Nota' => 'normal'],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);
        $this->assertXlsxIsReadable($result->path);

        $texto = (string) $this->readRows($result->path)[1][0];
        $this->assertStringContainsString('Dolor abdominal', $texto);
        $this->assertStringContainsString('agudo', $texto);
    }

    public function test_escapa_ampersand_y_angulos_sin_corromper(): void
    {
        $gz = $this->writeGz([
            ['Entidad' => 'JUAN & HIJOS <SAS> "LTDA"'],
            ['Entidad' => 'otra'],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);
        $this->assertXlsxIsReadable($result->path);

        $this->assertSame('JUAN & HIJOS <SAS> "LTDA"', (string) $this->readRows($result->path)[1][0]);
    }

    public function test_conserva_acentos_chino_y_emojis(): void
    {
        $gz = $this->writeGz([
            ['Nombre' => 'MARÍA JOSÉ ÑUÑEZ 中文 😀'],
            ['Nombre' => 'otro'],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $this->assertSame('MARÍA JOSÉ ÑUÑEZ 中文 😀', (string) $this->readRows($result->path)[1][0]);
    }

    /**
     * Regresión: las filas con Latin-1 crudo o caracteres de control crudos
     * hacían fallar json_decode y DESAPARECÍAN del Excel (se perdía ~20%).
     */
    public function test_no_pierde_filas_con_ndjson_malformado(): void
    {
        $gz = $this->writeGz([
            '{"Id":1,"Nota":"normal"}',
            "{\"Id\":2,\"Nota\":\"ni\xF1o latin1\"}",
            "{\"Id\":3,\"Nota\":\"ctrl\x01char\"}",
            '{"Id":4,"Nota":"fin"}',
        ], raw: true);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');

        $this->assertNotNull($result);
        $this->assertSame(4, $result->rows, 'Ninguna fila debe perderse');
        $this->assertXlsxIsReadable($result->path);
    }

    public function test_tolera_filas_con_claves_en_otro_orden_o_ausentes(): void
    {
        $gz = $this->writeGz([
            ['A' => 'a1', 'B' => 'b1', 'C' => 'c1'],
            ['B' => 'b2', 'A' => 'a2', 'C' => 'c2'],
            ['A' => 'a3', 'C' => 'c3'],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);
        $this->assertSame(['A', 'B', 'C'], $rows[0]);
        $this->assertSame(['a2', 'b2', 'c2'], $rows[2], 'El orden lo manda la cabecera');
        $this->assertSame('a3', (string) $rows[3][0]);
        $this->assertSame('', (string) $rows[3][1], 'B ausente queda vacía');
        $this->assertSame('c3', (string) $rows[3][2], 'C no debe correrse');
    }

    public function test_soporta_columnas_con_nombre_numerico(): void
    {
        $gz = $this->writeGz([['315' => 1, '051' => 54, 'Producto' => 'ALOPURINOL']]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Pivot');
        $this->assertNotNull($result);

        $this->assertSame(
            ['315', '051', 'Producto'],
            array_map('strval', $this->readRows($result->path)[0])
        );
    }

    // =========================================================================
    // Presentación: la hoja tiene que quedar legible
    // =========================================================================

    /**
     * Regresión: una nota de historia clínica con párrafos hacía que la fila
     * ocupara toda la pantalla y la hoja fuera ilegible.
     */
    public function test_las_notas_largas_no_estiran_la_fila(): void
    {
        $nota = "PRIMERA LINEA DE LA NOTA\nSEGUNDA LINEA\nTERCERA LINEA\n" . str_repeat('detalle clinico ', 100);

        $gz = $this->writeGz([
            ['Analisis' => $nota, 'Codigo' => 'I872'],
            ['Analisis' => 'corta', 'Codigo' => 'J100'],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_HC');
        $this->assertNotNull($result);

        $celda = (string) $this->readRows($result->path)[1][0];

        $this->assertStringNotContainsString("\n", $celda, 'Sin saltos: la fila no debe crecer');
        $this->assertLessThanOrEqual(300, mb_strlen($celda), 'El texto debe venir recortado');
        $this->assertStringEndsWith('…', $celda, 'El corte se marca para que se sepa que hay más');
    }

    public function test_declara_alto_de_fila_fijo_y_anchos_topados(): void
    {
        // Columna con valores largos y otra con valores cortos
        $gz = $this->writeGz([
            ['Corta' => 'ab', 'Larga' => str_repeat('x', 500)],
            ['Corta' => 'cd', 'Larga' => str_repeat('y', 500)],
        ]);

        $result = SpoutXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        $zip = new ZipArchive();
        $zip->open($result->path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        // Alto de fila declarado (evita que Excel las estire)
        $this->assertMatchesRegularExpression('/defaultRowHeight="15/', $sheet);

        // Los anchos se declaran y ninguno pasa del tope
        $this->assertMatchesRegularExpression('/<cols>/', $sheet);
        preg_match_all('/width="([\d.]+)"/', $sheet, $m);
        $this->assertNotEmpty($m[1], 'Debe declarar anchos de columna');

        foreach ($m[1] as $w) {
            $this->assertLessThanOrEqual(45.0, (float) $w, 'Ninguna columna debe pasar el ancho máximo');
            $this->assertGreaterThanOrEqual(10.0, (float) $w, 'Ninguna columna debe quedar más angosta que el mínimo');
        }
    }

    public function test_maneja_mas_de_26_columnas(): void
    {
        $row = [];
        for ($i = 0; $i < 60; $i++) {
            $row["Col{$i}"] = "v{$i}";
        }

        $result = SpoutXlsxWriter::fromNdjsonGz(
            $this->writeGz([$row, $row]),
            $this->tmpDir,
            'export',
            'VW_Ancha'
        );

        $this->assertNotNull($result);
        $this->assertXlsxIsReadable($result->path);

        $rows = $this->readRows($result->path);
        $this->assertCount(60, $rows[0]);
        $this->assertSame('v59', (string) $rows[1][59]);
    }

    public function test_recorta_el_nombre_de_hoja_al_limite_de_excel(): void
    {
        $result = SpoutXlsxWriter::fromNdjsonGz(
            $this->writeGz([['A' => 1]]),
            $this->tmpDir,
            'export',
            'VW_Nombre_Extremadamente_Largo_Que_Excel_No_Acepta_De_Ninguna_Forma'
        );

        $this->assertNotNull($result);

        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($result->path);
        foreach ($reader->getSheetIterator() as $sheet) {
            $this->assertLessThanOrEqual(31, mb_strlen($sheet->getName()));
            break;
        }
        $reader->close();
    }
}
