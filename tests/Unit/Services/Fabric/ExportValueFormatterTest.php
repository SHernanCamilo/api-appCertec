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
}
