<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fabric;

use App\Services\Fabric\Export\ExportValueFormatter;
use App\Services\Fabric\Export\FastXlsxWriter;
use Tests\TestCase;

/**
 * Fija el comportamiento del escritor rápido de xlsx.
 *
 * Extiende Tests\TestCase (no el de PHPUnit puro) porque el writer usa los
 * helpers de Laravel now() y Log en el camino de éxito.
 *
 * FastXlsxWriter arma el XML de la hoja a mano, así que hay dos riesgos que
 * estos tests cubren de forma explícita:
 *
 *   1. Un XML mal formado hace que Excel declare el archivo dañado. Cada test
 *      abre el resultado con el lector de OpenSpout, que valida la estructura.
 *   2. Los seriales de fecha se calculan con aritmética de calendario en vez de
 *      DateTime (por velocidad). Se comparan contra la implementación con
 *      DateTime para que no se desvíen ni un segundo.
 */
final class FastXlsxWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/fast_xlsx_test_' . uniqid();
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

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function writeNdjsonGz(array $rows): string
    {
        $path = $this->tmpDir . '/data.ndjson.gz';
        $gz   = gzopen($path, 'wb1');

        foreach ($rows as $row) {
            gzwrite($gz, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
        }

        gzclose($gz);

        return $path;
    }

    /**
     * @return list<list<mixed>>
     */
    private function readRows(string $xlsxPath): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($xlsxPath);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    // =========================================================================
    // Estructura del archivo
    // =========================================================================

    public function test_genera_un_xlsx_valido_con_cabecera_y_datos(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Sede' => 'Neiva', 'Valor' => 100],
            ['Sede' => 'Bogota', 'Valor' => 200],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');

        $this->assertNotNull($result);
        $this->assertSame('xlsx', $result->format);
        $this->assertSame(2, $result->rows);
        $this->assertFileExists($result->path);

        $rows = $this->readRows($result->path);

        $this->assertCount(3, $rows, 'Cabecera + 2 filas');
        $this->assertSame(['Sede', 'Valor'], $rows[0]);
        $this->assertSame('Neiva', $rows[1][0]);
        $this->assertSame(100.0, (float) $rows[1][1]);
    }

    public function test_el_xlsx_es_un_zip_con_las_partes_ooxml_obligatorias(): void
    {
        $gz     = $this->writeNdjsonGz([['A' => 1]]);
        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');

        $this->assertNotNull($result);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result->path) === true);

        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ] as $part) {
            $this->assertNotFalse($zip->locateName($part), "Falta la parte {$part}");
        }

        $zip->close();
    }

    public function test_no_deja_el_xml_temporal_de_la_hoja(): void
    {
        $gz     = $this->writeNdjsonGz([['A' => 1]]);
        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');

        $this->assertNotNull($result);
        $this->assertSame([], glob($this->tmpDir . '/*.sheet.xml') ?: []);
    }

    public function test_devuelve_null_si_el_archivo_no_tiene_filas(): void
    {
        $gz = $this->writeNdjsonGz([]);

        $this->assertNull(FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test'));
    }

    public function test_devuelve_null_si_el_gz_no_existe(): void
    {
        $this->assertNull(
            FastXlsxWriter::fromNdjsonGz($this->tmpDir . '/no_existe.gz', $this->tmpDir, 'export', 'VW')
        );
    }

    // =========================================================================
    // Tipos de celda
    // =========================================================================

    public function test_conserva_los_ceros_iniciales_de_nit_y_documentos(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Documento' => '036004835', 'Cantidad' => 15],
            ['Documento' => '007112233', 'Cantidad' => 20],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);

        $this->assertSame('036004835', (string) $rows[1][0]);
        $this->assertSame('007112233', (string) $rows[2][0]);
    }

    /**
     * PRUEBA DE INTEGRIDAD END-TO-END del texto largo (writer rápido).
     *
     * Es el camino que corre para las vistas grandes (historia clínica), así que
     * es el que de verdad genera el Excel que descarga el usuario.
     *
     * Contexto: se reportó que el campo "Analisis" salía cortado. Se midió el
     * NDJSON de Graph-Fabric y el texto ya llegaba truncado a 8.000 caracteres
     * desde el origen (límite del SQL Analytics Endpoint de Fabric). Este test
     * fija que el writer NO agrega recorte propio: lo que entra, sale.
     */
    public function test_el_texto_largo_sobrevive_intacto_el_viaje_al_xlsx(): void
    {
        $nota = '';
        $i    = 0;
        while (mb_strlen($nota) < 8000) {
            $nota .= "PARRAFO {$i}: EVOLUCION MEDICA CON HALLAZGOS Y PLAN TERAPEUTICO. ";
            $i++;
        }
        $nota = mb_substr($nota, 0, 8000);

        $gz = $this->writeNdjsonGz([['Analisis' => $nota]]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_HC');
        $this->assertNotNull($result);

        $celda = (string) $this->readRows($result->path)[1][0];

        $this->assertSame(
            8000,
            mb_strlen($celda),
            'El writer rapido debe conservar los 8000 caracteres del NDJSON'
        );
        $this->assertSame($nota, $celda, 'El texto del xlsx debe ser identico al del NDJSON');
    }

    public function test_los_numeros_salen_como_numero_para_poder_sumarlos(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Valor' => 1500.75],
            ['Valor' => 2300],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);

        $this->assertIsNumeric($rows[1][0]);
        $this->assertSame(1500.75, (float) $rows[1][0]);
        $this->assertSame(2300.0, (float) $rows[2][0]);
    }

    /**
     * Una llave compuesta de 37 dígitos no cabe en un double: salía como
     * 6,00621E+36 o directamente como INF.
     */
    public function test_las_llaves_muy_largas_van_como_texto(): void
    {
        $llave = '6006205000000000000000000000000000001';
        $gz    = $this->writeNdjsonGz([['Llave' => $llave], ['Llave' => $llave]]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $this->assertSame($llave, (string) $this->readRows($result->path)[1][0]);
    }

    public function test_las_fechas_salen_como_fecha_real_de_excel(): void
    {
        $gz = $this->writeNdjsonGz([
            ['FechaIngreso' => '2026-08-28T14:35:09', 'FechaCorte' => '2026-08-28'],
            ['FechaIngreso' => '2026-01-15T08:00:00', 'FechaCorte' => '2026-01-15'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);

        // OpenSpout devuelve DateTime cuando la celda tiene formato de fecha:
        // eso confirma que se escribió el serial + el estilo, no un string.
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][0]);
        $this->assertSame('2026-08-28 14:35:09', $rows[1][0]->format('Y-m-d H:i:s'));

        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][1]);
        $this->assertSame('2026-08-28', $rows[1][1]->format('Y-m-d'));
    }

    /**
     * El cálculo rápido de seriales reemplaza a DateTime por aritmética de
     * calendario. Si se desvía, las fechas del Excel quedan corridas.
     */
    public function test_el_serial_rapido_coincide_con_el_calculado_con_datetime(): void
    {
        $fechas = [
            '2026-08-28',
            '2026-08-28 14:35:09',
            '2026-08-28T14:35:09',
            '1900-03-01',
            '2000-02-29',
            '2024-12-31 23:59:59',
            '1999-01-01 00:00:00',
        ];

        $method = new \ReflectionMethod(FastXlsxWriter::class, 'excelSerial');
        $method->setAccessible(true);
        $cache = [];

        foreach ($fechas as $fecha) {
            $rapido = $method->invokeArgs(null, [$fecha, &$cache]);
            $lento  = ExportValueFormatter::toExcelSerial($fecha);

            $this->assertNotNull($lento, "toExcelSerial no reconoció {$fecha}");
            $this->assertNotNull($rapido, "excelSerial no reconoció {$fecha}");
            $this->assertEqualsWithDelta(
                $lento,
                (float) $rapido,
                0.0000012, // menos de un segundo
                "El serial de {$fecha} no coincide"
            );
        }
    }

    public function test_ignora_fechas_anteriores_a_1900_que_excel_no_representa(): void
    {
        $method = new \ReflectionMethod(FastXlsxWriter::class, 'excelSerial');
        $method->setAccessible(true);
        $cache = [];

        $this->assertNull($method->invokeArgs(null, ['1899-12-31', &$cache]));
        $this->assertNull($method->invokeArgs(null, ['no-es-fecha', &$cache]));
    }

    // =========================================================================
    // Robustez del XML
    // =========================================================================

    public function test_escapa_los_caracteres_que_romperian_el_xml(): void
    {
        $texto = 'Nota <urgente> & "revisada" con \'comillas\'';
        $gz    = $this->writeNdjsonGz([['Observacion' => $texto], ['Observacion' => $texto]]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $this->assertSame($texto, (string) $this->readRows($result->path)[1][0]);
    }

    /**
     * Un \x00 dentro del texto hace que Excel rechace el archivo completo.
     * Viene de campos varbinary mal casteados en SQL Server.
     */
    public function test_elimina_los_caracteres_de_control_prohibidos(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Observacion' => "ANTES\x00DESPUES"],
            ['Observacion' => "ANTES\x00DESPUES"],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $this->assertSame('ANTESDESPUES', (string) $this->readRows($result->path)[1][0]);
    }

    public function test_tolera_filas_con_las_claves_en_otro_orden(): void
    {
        $gz = $this->writeNdjsonGz([
            ['A' => 'a1', 'B' => 'b1'],
            ['B' => 'b2', 'A' => 'a2'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);

        $this->assertSame(['A', 'B'], $rows[0]);
        $this->assertSame(['a2', 'b2'], $rows[2], 'El orden lo manda la cabecera, no la fila');
    }

    public function test_tolera_columnas_ausentes_sin_desplazar_los_valores(): void
    {
        $gz = $this->writeNdjsonGz([
            ['A' => 'a1', 'B' => 'b1', 'C' => 'c1'],
            ['A' => 'a2', 'C' => 'c2'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Test');
        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);

        $this->assertSame('a2', (string) $rows[2][0]);
        $this->assertSame('', (string) $rows[2][1], 'B ausente queda vacía');
        $this->assertSame('c2', (string) $rows[2][2], 'C no debe correrse a la columna de B');
    }

    public function test_soporta_columnas_con_nombre_numerico_de_las_vistas_pivot(): void
    {
        $gz = $this->writeNdjsonGz([
            ['315' => 1, '051' => 54, 'Producto' => 'ALOPURINOL'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Pivot');
        $this->assertNotNull($result);

        $this->assertSame(
            ['315', '051', 'Producto'],
            array_map('strval', $this->readRows($result->path)[0])
        );
    }

    public function test_recorta_el_nombre_de_hoja_al_limite_de_excel(): void
    {
        $gz = $this->writeNdjsonGz([['A' => 1]]);

        $result = FastXlsxWriter::fromNdjsonGz(
            $gz,
            $this->tmpDir,
            'export',
            'VW_Nombre_De_Vista_Extremadamente_Largo_Que_Excel_No_Acepta'
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

    // =========================================================================
    // Portada corporativa (title != null)
    // =========================================================================

    public function test_con_titulo_escribe_portada_y_los_datos_bajan_tres_filas(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Sede' => 'Neiva', 'Valor' => 100],
            ['Sede' => 'Bogota', 'Valor' => 200],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz(
            $gz,
            $this->tmpDir,
            'export',
            'VW_AD_Paciente',
            'dc.VW_AD_Paciente'
        );

        $this->assertNotNull($result);
        $this->assertSame(2, $result->rows, 'La portada no debe contarse como fila de datos');

        $rows = $this->readRows($result->path);

        // Fila 1 título, fila 2 info, fila 3 encabezados, fila 4+ datos
        $this->assertStringContainsString('dc.VW_AD_Paciente', (string) $rows[0][0]);
        $this->assertStringContainsString('Exportado:', (string) $rows[1][0]);
        $this->assertSame(['Sede', 'Valor'], $rows[2]);
        $this->assertSame('Neiva', (string) $rows[3][0]);
        $this->assertSame(100.0, (float) $rows[3][1]);
    }

    public function test_la_portada_no_rompe_el_autofiltro_ni_las_fechas(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Fecha' => '2026-08-28T14:35:09', 'Doc' => '007112233'],
            ['Fecha' => '2026-01-15T00:00:00', 'Doc' => '008990011'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW', 'dc.VW');
        $this->assertNotNull($result);

        // El lector estricto de OpenSpout valida la estructura del xlsx
        $rows = $this->readRows($result->path);

        $this->assertInstanceOf(\DateTimeInterface::class, $rows[3][0], 'La fecha sigue siendo fecha real');
        $this->assertSame('007112233', (string) $rows[3][1], 'El cero inicial se conserva');
    }

    /**
     * Regresión: gd.VW_Glosa_EstadisticoGlosas_Fla traía valores con U+FFFE, un
     * codepoint que XML 1.0 prohíbe pero que es UTF-8 válido, así que pasaba el
     * filtro viejo (strpbrk single-byte) y llegaba crudo al XML. Excel lo abría
     * "reparando" y quitaba la hoja: "Cargar error. Línea N".
     */
    public function test_elimina_codepoints_unicode_prohibidos_por_xml(): void
    {
        // U+FFFE y U+FFFF son válidos en UTF-8 pero ilegales en XML 1.0
        $gz = $this->writeNdjsonGz([
            ['Entidad' => "MAL\u{FFFE}CHAR\u{FFFF}"],
            ['Entidad' => 'NORMAL'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Glosa');
        $this->assertNotNull($result);

        // El XML de la hoja debe validar (Excel no lo "reparará")
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result->path) === true);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($sheetXml);
        libxml_use_internal_errors($prev);

        $this->assertNotFalse($doc, 'El XML de la hoja debe ser válido para que Excel no lo repare');

        // El texto queda sin los codepoints prohibidos, conservando lo legible
        $this->assertSame('MALCHAR', (string) $this->readRows($result->path)[1][0]);
    }

    /**
     * Regresión: hg.VW_HC_EvolucionesEspecialistas_Tja traía en las notas de
     * historia clínica bytes Latin-1 crudos (ñ mal codificada, secuencias
     * truncas). json_decode devolvía null y esas filas DESAPARECÍAN del Excel.
     *
     * Aquí se escribe NDJSON con bytes inválidos crudos (como los manda el
     * pipeline real, no vía json_encode) y se verifica que ninguna fila se
     * pierde y que el XML sigue siendo válido.
     */
    public function test_recupera_filas_con_utf8_invalido_sin_perderlas(): void
    {
        $path = $this->tmpDir . '/raw.ndjson.gz';
        $gz   = gzopen($path, 'wb1');
        // Fila 1 normal, fila 2 con ñ en Latin-1 (0xF1), fila 3 con secuencia
        // UTF-8 trunca (0xE2 0x82 sin el tercer byte).
        gzwrite($gz, "{\"Id\":1,\"Nota\":\"normal\"}\n");
        gzwrite($gz, "{\"Id\":2,\"Nota\":\"ni\xF1o enfermo\"}\n");
        gzwrite($gz, "{\"Id\":3,\"Nota\":\"corte\xE2\x82aqui\"}\n");
        gzclose($gz);

        $result = FastXlsxWriter::fromNdjsonGz($path, $this->tmpDir, 'export', 'VW_HC');

        $this->assertNotNull($result);
        $this->assertSame(3, $result->rows, 'Ninguna fila con UTF-8 inválido debe perderse');

        // Y el XML de la hoja debe validar
        $zip = new \ZipArchive();
        $zip->open($result->path);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($sheetXml);
        libxml_use_internal_errors($prev);

        $this->assertNotFalse($doc, 'El XML debe ser válido pese a los bytes Latin-1');
    }

    public function test_conserva_emojis_y_caracteres_multibyte_validos(): void
    {
        $gz = $this->writeNdjsonGz([
            ['Nota' => 'Chino 中文 y emoji 😀'],
            ['Nota' => 'ok'],
        ]);

        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW');
        $this->assertNotNull($result);

        // No se deben sobre-limpiar los codepoints válidos
        $this->assertSame('Chino 中文 y emoji 😀', (string) $this->readRows($result->path)[1][0]);
    }

    public function test_maneja_mas_columnas_que_la_z(): void
    {
        $row = [];
        for ($i = 0; $i < 60; $i++) {
            $row["Col{$i}"] = "v{$i}";
        }

        $gz     = $this->writeNdjsonGz([$row, $row]);
        $result = FastXlsxWriter::fromNdjsonGz($gz, $this->tmpDir, 'export', 'VW_Ancha');

        $this->assertNotNull($result);

        $rows = $this->readRows($result->path);

        $this->assertCount(60, $rows[0], 'Las 60 columnas deben llegar completas');
        $this->assertSame('v59', (string) $rows[1][59], 'La columna BH no debe perderse');
    }
}
