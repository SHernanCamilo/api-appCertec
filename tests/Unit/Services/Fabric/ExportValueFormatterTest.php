<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fabric;

use App\Services\Fabric\Export\ExportValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cubre las reglas de formato que evitan que Excel corrompa los datos.
 *
 * El bug original: "036004835" (Nro_Cuenta) llegaba a Excel como 36004835.
 * Estos tests fijan el comportamiento para que no vuelva a regresar.
 */
final class ExportValueFormatterTest extends TestCase
{
    // =========================================================================
    // detectTextColumns — por nombre de columna
    // =========================================================================

    public function test_detecta_columna_de_texto_por_nombre(): void
    {
        $headers = ['Sede', 'Nro_Cuenta', 'Valor'];

        $result = ExportValueFormatter::detectTextColumns($headers);

        $this->assertArrayHasKey(1, $result, 'Nro_Cuenta debe marcarse como texto');
        $this->assertArrayNotHasKey(0, $result, 'Sede no debe marcarse como texto');
        $this->assertArrayNotHasKey(2, $result, 'Valor no debe marcarse como texto');
    }

    #[DataProvider('nombresDeColumnaDeTexto')]
    public function test_reconoce_todos_los_patrones_de_texto(string $header): void
    {
        $result = ExportValueFormatter::detectTextColumns([$header]);

        $this->assertArrayHasKey(0, $result, "'{$header}' debería tratarse como texto");
    }

    /** @return array<string, array{string}> */
    public static function nombresDeColumnaDeTexto(): array
    {
        return [
            'Nro_Cuenta'      => ['Nro_Cuenta'],
            'numero_cuenta'   => ['numero_cuenta'],
            'Cuenta'          => ['Cuenta'],
            'NIT'             => ['NIT'],
            'Documento'       => ['Documento'],
            'Cedula'          => ['Cedula'],
            'Placa'           => ['Placa'],
            'Codigo'          => ['Codigo'],
            'Telefono'        => ['Telefono'],
            'Consecutivo'     => ['Consecutivo'],
            'Referencia'      => ['Referencia'],
            'Poliza'          => ['Poliza'],
            'Contrato'        => ['Contrato'],
            'mayusculas'      => ['NRO_CUENTA'],
            'como sufijo'     => ['Banco_Nit'],
        ];
    }

    // =========================================================================
    // detectTextColumns — columnas con nombre numérico (vistas pivot)
    // =========================================================================

    public function test_no_falla_con_headers_numericos(): void
    {
        // Reproduce el crash de VW_Inventory_AlmacenesPivot_315_Cmi:
        // array_keys(['315' => 1]) devuelve [315] como int, y strtolower(int)
        // lanza TypeError en PHP 8.
        $headers = ['315', '051', 'Producto'];

        $result = ExportValueFormatter::detectTextColumns($headers, [1, 54, 'ALOPURINOL']);

        $this->assertIsArray($result);
    }

    public function test_acepta_headers_int_sin_castear(): void
    {
        /** @var list<string> $headers */
        $headers = array_map('strval', array_keys(['315' => 1, 'Codigo' => 'ABC']));

        $result = ExportValueFormatter::detectTextColumns($headers);

        $this->assertSame(['315', 'Codigo'], $headers);
        $this->assertArrayHasKey(1, $result, 'Codigo debe ser texto');
    }

    // =========================================================================
    // detectTextColumns — por contenido de la primera fila
    // =========================================================================

    public function test_detecta_texto_por_cero_inicial_en_primera_fila(): void
    {
        $headers  = ['Campo_Sin_Patron'];
        $firstRow = ['036004835'];

        $result = ExportValueFormatter::detectTextColumns($headers, $firstRow);

        $this->assertArrayHasKey(0, $result, 'Un valor con cero inicial debe forzar texto');
    }

    public function test_no_marca_texto_un_numero_normal(): void
    {
        $result = ExportValueFormatter::detectTextColumns(['Cantidad'], ['1500']);

        $this->assertArrayNotHasKey(0, $result);
    }

    public function test_tolera_primera_fila_mas_corta_que_headers(): void
    {
        $result = ExportValueFormatter::detectTextColumns(['A', 'B', 'C'], ['x']);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // forCsv
    // =========================================================================

    public function test_forcsv_protege_columna_de_texto_numerica(): void
    {
        $result = ExportValueFormatter::forCsv('036004835', true);

        $this->assertSame('="036004835"', $result);
    }

    public function test_forcsv_no_envuelve_texto_no_numerico(): void
    {
        $result = ExportValueFormatter::forCsv('ABC-123', true);

        $this->assertSame('ABC-123', $result);
    }

    public function test_forcsv_protege_cero_inicial_aunque_no_sea_columna_de_texto(): void
    {
        $result = ExportValueFormatter::forCsv('0051', false);

        $this->assertSame('="0051"', $result);
    }

    public function test_forcsv_limpia_decimales_innecesarios(): void
    {
        $this->assertSame('1500', ExportValueFormatter::forCsv('1500.00', false));
        $this->assertSame('1500.5', ExportValueFormatter::forCsv('1500.50', false));
    }

    public function test_forcsv_convierte_float_entero_a_int(): void
    {
        $this->assertSame(2150, ExportValueFormatter::forCsv(2150.0, false));
        $this->assertSame(2150.75, ExportValueFormatter::forCsv(2150.75, false));
    }

    public function test_forcsv_respeta_null_y_vacio(): void
    {
        $this->assertNull(ExportValueFormatter::forCsv(null, true));
        $this->assertSame('', ExportValueFormatter::forCsv('', true));
    }

    public function test_forcsv_deja_intacto_un_entero(): void
    {
        $this->assertSame(59, ExportValueFormatter::forCsv(59, false));
    }

    // =========================================================================
    // sanitize
    // =========================================================================

    public function test_sanitize_reemplaza_saltos_de_linea(): void
    {
        $input = "ALOPURINOL 300 MG\r\nCAJA X 30\tTABLETAS";

        $result = ExportValueFormatter::sanitize($input);

        $this->assertSame('ALOPURINOL 300 MG CAJA X 30 TABLETAS', $result);
    }

    public function test_sanitize_no_altera_valores_no_string(): void
    {
        $this->assertSame(59, ExportValueFormatter::sanitize(59));
        $this->assertNull(ExportValueFormatter::sanitize(null));
        $this->assertSame(1.5, ExportValueFormatter::sanitize(1.5));
    }

    // =========================================================================
    // looksLikeIsoDate
    // =========================================================================

    public function test_reconoce_fecha_iso(): void
    {
        // looksLikeIsoDate exige hora; las fechas sin hora las cubre looksLikeDateOnly
        $this->assertTrue(ExportValueFormatter::looksLikeIsoDate('2026-07-30 14:05:00'));
        $this->assertTrue(ExportValueFormatter::looksLikeIsoDate('2026-07-30T14:05:00'));
        $this->assertTrue(ExportValueFormatter::looksLikeDateOnly('2026-07-30'));
    }

    // =========================================================================
    // isSafeExcelNumber — evita notación científica e INF
    // =========================================================================

    public function test_acepta_numeros_dentro_de_la_precision_de_excel(): void
    {
        $this->assertTrue(ExportValueFormatter::isSafeExcelNumber('123'));
        $this->assertTrue(ExportValueFormatter::isSafeExcelNumber('999999999999999')); // 15 dígitos
        $this->assertTrue(ExportValueFormatter::isSafeExcelNumber('1500.50'));
        $this->assertTrue(ExportValueFormatter::isSafeExcelNumber('-42'));
        $this->assertTrue(ExportValueFormatter::isSafeExcelNumber(0));
    }

    public function test_rechaza_numeros_que_excel_redondearia(): void
    {
        // 16+ dígitos: Excel los mostraría como 1E+15 y perdería el detalle
        $this->assertFalse(ExportValueFormatter::isSafeExcelNumber('1000000000000000'));
        // Llave compuesta real que salía como 6,00621E+36
        $this->assertFalse(
            ExportValueFormatter::isSafeExcelNumber('6006205000000000000000000000000000000001')
        );
        // Desbordamiento del double: salía como INF
        $this->assertFalse(ExportValueFormatter::isSafeExcelNumber(str_repeat('9', 320)));
        // Cero inicial: debe conservarse como texto
        $this->assertFalse(ExportValueFormatter::isSafeExcelNumber('036004835'));
        // Notación científica en el origen
        $this->assertFalse(ExportValueFormatter::isSafeExcelNumber('1E36'));
        $this->assertFalse(ExportValueFormatter::isSafeExcelNumber('ABC'));
        $this->assertFalse(ExportValueFormatter::isSafeExcelNumber(INF));
    }

    public function test_csv_protege_numeros_largos_como_texto(): void
    {
        $llave = '6006205000000000000000000000000000000001';

        $this->assertSame(
            '="' . $llave . '"',
            ExportValueFormatter::forCsv($llave, false),
            'Una llave larga debe ir como fórmula de texto para no salir en notación científica'
        );
    }

    public function test_rechaza_lo_que_no_es_fecha_iso(): void
    {
        $this->assertFalse(ExportValueFormatter::looksLikeIsoDate('30/07/2026'));
        $this->assertFalse(ExportValueFormatter::looksLikeIsoDate('036004835'));
        $this->assertFalse(ExportValueFormatter::looksLikeIsoDate(20260730));
        $this->assertFalse(ExportValueFormatter::looksLikeIsoDate(null));
    }

    // =========================================================================
    // xmlSafe — un .xlsx es XML; un caracter ilegal hace que Excel lo "repare"
    // =========================================================================

    public function test_xmlsafe_quita_caracteres_de_control(): void
    {
        $this->assertSame('ABCD', ExportValueFormatter::xmlSafe("AB\x01\x08\x1FCD"));
        $this->assertSame('LINEA1LINEA2', ExportValueFormatter::xmlSafe("LINEA1\x0BLINEA2"));
    }

    /**
     * U+FFFE y U+FFFF son UTF-8 VÁLIDO, así que htmlspecialchars los deja pasar,
     * pero XML 1.0 los prohíbe. Rompían el xlsx de glosas e historia clínica.
     */
    public function test_xmlsafe_quita_codepoints_prohibidos_por_xml(): void
    {
        $this->assertSame('MALCHAR', ExportValueFormatter::xmlSafe("MAL\u{FFFE}CHAR"));
        $this->assertSame('AB', ExportValueFormatter::xmlSafe("A\u{FFFF}B"));
    }

    public function test_xmlsafe_conserva_acentos_emojis_y_saltos_legitimos(): void
    {
        $this->assertSame('áéíóú ñÑ', ExportValueFormatter::xmlSafe('áéíóú ñÑ'));
        $this->assertSame('中文 😀', ExportValueFormatter::xmlSafe('中文 😀'));
        // Tab, LF y CR SÍ son válidos en XML
        $this->assertSame("a\tb\nc\rd", ExportValueFormatter::xmlSafe("a\tb\nc\rd"));
    }

    public function test_xmlsafe_tolera_utf8_corrupto_sin_devolver_vacio(): void
    {
        // Byte Latin-1 suelto: no debe reventar ni vaciar toda la cadena
        $result = ExportValueFormatter::xmlSafe("ni\xF1o enfermo");

        $this->assertNotSame('', $result);
        $this->assertStringContainsString('enfermo', $result);
    }

    public function test_sanitize_aplica_el_saneo_xml_ademas_de_los_saltos(): void
    {
        // sanitize lo usa el camino clásico (writeRow → CSV → OpenSpout)
        $this->assertSame(
            'NOTA MEDICA',
            ExportValueFormatter::sanitize("NOTA\n\x01MEDICA")
        );
    }

    // =========================================================================
    // Presentación de la celda: sin saltos, sin espacios dobles y recortada
    // =========================================================================

    /**
     * Las notas de historia clínica traen párrafos con saltos de línea. Si se
     * escriben tal cual, la fila del Excel se estira hasta ocupar la pantalla.
     */
    public function test_colapsa_los_saltos_de_linea_a_un_espacio(): void
    {
        $nota = "PRIMERA LINEA\nSEGUNDA LINEA\r\nTERCERA\tCUARTA";

        $result = (string) ExportValueFormatter::sanitize($nota);

        $this->assertStringNotContainsString("\n", $result);
        $this->assertStringNotContainsString("\r", $result);
        $this->assertStringNotContainsString("\t", $result);
        $this->assertSame('PRIMERA LINEA SEGUNDA LINEA TERCERA CUARTA', $result);
    }

    public function test_colapsa_espacios_repetidos(): void
    {
        $this->assertSame(
            'TEXTO CON ESPACIOS',
            ExportValueFormatter::sanitize("TEXTO    CON\n\n  ESPACIOS")
        );
    }

    public function test_no_recorta_notas_largas_que_caben_en_una_celda(): void
    {
        // Notas de historia clínica (Analisis, Observacion): párrafos enteros.
        // Deben salir COMPLETOS mientras no superen el límite duro de Excel.
        $largo = str_repeat('A', 1000);

        $result = (string) ExportValueFormatter::sanitize($largo);

        $this->assertSame(1000, mb_strlen($result));
        $this->assertStringNotContainsString('…', $result);
    }

    public function test_recorta_solo_al_superar_el_limite_de_excel(): void
    {
        // Excel no admite más de 32.767 caracteres por celda: pasarse corrompe
        // el .xlsx. El recorte queda solo como salvaguarda contra eso.
        $largo = str_repeat('A', ExportValueFormatter::MAX_CELL_TEXT + 500);

        $result = (string) ExportValueFormatter::sanitize($largo);

        $this->assertSame(ExportValueFormatter::MAX_CELL_TEXT, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }

    public function test_no_toca_el_texto_que_ya_cabe(): void
    {
        $corto = 'INSUFICIENCIA VENOSA (CRONICA) (PERIFERICA)';

        $this->assertSame($corto, ExportValueFormatter::sanitize($corto));
    }

    public function test_recorta_sin_partir_caracteres_multibyte(): void
    {
        // Emojis suficientes para pasar el límite: si se cortara por bytes,
        // quedaría un carácter roto al final.
        $result = (string) ExportValueFormatter::sanitize(
            str_repeat('😀', ExportValueFormatter::MAX_CELL_TEXT + 100)
        );

        $this->assertSame(ExportValueFormatter::MAX_CELL_TEXT, mb_strlen($result));
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'El recorte debe dejar UTF-8 válido');
    }

    public function test_no_altera_los_valores_que_no_son_texto(): void
    {
        $this->assertSame(1500, ExportValueFormatter::sanitize(1500));
        $this->assertSame(12.5, ExportValueFormatter::sanitize(12.5));
        $this->assertNull(ExportValueFormatter::sanitize(null));
    }

    // =========================================================================
    // decodeNdjsonLine — ninguna fila debe perderse
    // =========================================================================

    public function test_decodifica_una_linea_normal(): void
    {
        $row = ExportValueFormatter::decodeNdjsonLine('{"Id":1,"Nota":"ok"}');

        $this->assertSame(['Id' => 1, 'Nota' => 'ok'], $row);
    }

    /**
     * Regresión: "niño" en Latin-1 (ni\xF1o) hacía que json_decode devolviera
     * null y la fila DESAPARECÍA del Excel sin aviso.
     */
    public function test_recupera_lineas_con_utf8_invalido(): void
    {
        $row = ExportValueFormatter::decodeNdjsonLine("{\"Id\":1,\"Nota\":\"ni\xF1o\"}");

        $this->assertIsArray($row, 'La fila con Latin-1 no debe perderse');
        $this->assertSame(1, $row['Id']);
    }

    /**
     * Regresión: JSON prohíbe \x00-\x1F sin escapar dentro de un string. Si el
     * origen escribió el byte crudo, json_decode fallaba con CTRL_CHAR y se
     * perdía la fila (medido: 20% de las filas en una vista de HC).
     */
    public function test_recupera_lineas_con_caracteres_de_control_crudos(): void
    {
        $row = ExportValueFormatter::decodeNdjsonLine("{\"Id\":7,\"Nota\":\"AB\x01CD\"}");

        $this->assertIsArray($row, 'La fila con control chars crudos no debe perderse');
        $this->assertSame(7, $row['Id']);
    }

    public function test_recupera_lineas_con_ambos_problemas_a_la_vez(): void
    {
        $row = ExportValueFormatter::decodeNdjsonLine("{\"Id\":9,\"Nota\":\"ni\xF1o\x01raro\"}");

        $this->assertIsArray($row);
        $this->assertSame(9, $row['Id']);
    }

    public function test_devuelve_null_solo_si_no_es_json_de_objeto(): void
    {
        $this->assertNull(ExportValueFormatter::decodeNdjsonLine(''));
        $this->assertNull(ExportValueFormatter::decodeNdjsonLine('   '));
        $this->assertNull(ExportValueFormatter::decodeNdjsonLine('esto no es json'));
        $this->assertNull(ExportValueFormatter::decodeNdjsonLine('{}'));
    }

    public function test_ignora_el_salto_de_linea_del_formato_ndjson(): void
    {
        // El \n final es del NDJSON, no del JSON: no debe romper la decodificación
        $row = ExportValueFormatter::decodeNdjsonLine("{\"Id\":3,\"Nota\":\"AB\x01CD\"}\n");

        $this->assertIsArray($row);
        $this->assertSame(3, $row['Id']);
    }
}
