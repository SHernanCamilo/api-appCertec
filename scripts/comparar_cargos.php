<?php
/**
 * Comparador de Cargos: AD vs Indigo
 *
 * Proceso:
 *   1. Leer ambos Excel
 *   2. Sanitizar: normalizar mayúsculas, espacios, romanos→arábigos, duplicados
 *   3. Comparar y generar 2 listas:
 *      - Cargos que coinciden (están en ambos)
 *      - Cargos que NO coinciden (solo en AD o solo en Indigo)
 *   4. Exportar a Excel
 */

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;

// ============================================================
// 1. FUNCIONES DE SANITIZACIÓN
// ============================================================

/**
 * Convierte arábigos a romanos (1-10).
 */
function arabicToRoman(string $num): string
{
    $map = [
        '10' => 'X', '9' => 'IX', '8' => 'VIII', '7' => 'VII',
        '6'  => 'VI', '5' => 'V', '4' => 'IV',   '3' => 'III',
        '2'  => 'II', '1' => 'I',
    ];
    return $map[trim($num)] ?? $num;
}

/**
 * Sanitiza un cargo:
 *   - Trim y colapsar espacios múltiples
 *   - Uppercase
 *   - Normalizar guiones pegados: "ENFERMERIA-TIPO" → "ENFERMERIA TIPO"
 *   - Normalizar "TIPO 1" / "TIPO Ii" / "TIPO ii" → "TIPO I" (romano uppercase)
 */
function sanitizarCargo(string $cargo): string
{
    // Trim y colapsar espacios
    $cargo = trim($cargo);
    $cargo = preg_replace('/\s+/', ' ', $cargo);

    // Normalizar guiones entre palabras → espacio
    $cargo = preg_replace('/\s*-\s*/', ' ', $cargo);

    // Uppercase
    $cargo = mb_strtoupper($cargo, 'UTF-8');

    // Normalizar "TIPO <arábigo>" → "TIPO <romano>"  (ej: TIPO 1 → TIPO I)
    $cargo = preg_replace_callback(
        '/\bTIPO\s+([0-9]+)\b/',
        function ($m) {
            return 'TIPO ' . arabicToRoman($m[1]);
        },
        $cargo
    );

    // Normalizar "TIPO <romano con mezcla de case>" → "TIPO <romano uppercase>"
    // Ej: "TIPO Ii" → "TIPO II", "TIPO iii" → "TIPO III"
    $cargo = preg_replace_callback(
        '/\bTIPO\s+([IVXivx]+)\b/',
        function ($m) {
            return 'TIPO ' . strtoupper($m[1]);
        },
        $cargo
    );

    // Colapsar espacios de nuevo
    $cargo = preg_replace('/\s+/', ' ', $cargo);

    return trim($cargo);
}

/**
 * Clave de comparación: elimina acentos y caracteres especiales para comparar.
 */
function claveComparacion(string $cargo): string
{
    $cargo = sanitizarCargo($cargo);

    // Quitar acentos
    $cargo = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargo);

    // Solo letras, números y espacios
    $cargo = preg_replace('/[^A-Z0-9 ]/', '', $cargo);

    // Colapsar espacios
    $cargo = preg_replace('/\s+/', ' ', $cargo);

    return trim($cargo);
}

// ============================================================
// 2. LEER ARCHIVOS
// ============================================================

echo "Leyendo archivos Excel..." . PHP_EOL;

$adRaw    = IOFactory::load('docs/Cargo AD.xlsx')->getActiveSheet()->toArray();
$indigoRaw = IOFactory::load('docs/Cargos Indigo.xlsx')->getActiveSheet()->toArray();

// Extraer valores de columna A, ignorar vacíos
$adValores     = array_filter(array_map(fn($r) => trim($r[0] ?? ''), $adRaw));
$indigoValores = array_filter(array_map(fn($r) => trim($r[0] ?? ''), $indigoRaw));

echo "AD bruto: "     . count($adValores)     . " registros" . PHP_EOL;
echo "Indigo bruto: " . count($indigoValores) . " registros" . PHP_EOL;

// ============================================================
// 3. SANITIZAR Y DEDUPLICAR AD
// ============================================================

// Mapa: clave_comparacion => cargo_sanitizado (guardamos el sanitizado para mostrar)
$adMap = [];
foreach ($adValores as $cargo) {
    $sanitizado = sanitizarCargo($cargo);
    $clave      = claveComparacion($cargo);
    if ($clave !== '' && !isset($adMap[$clave])) {
        $adMap[$clave] = $sanitizado;
    }
}

// Sanitizar Indigo (ya vienen más limpios, pero aplicamos mismo proceso)
$indigoMap = [];
foreach ($indigoValores as $cargo) {
    $sanitizado = sanitizarCargo($cargo);
    $clave      = claveComparacion($cargo);
    if ($clave !== '' && !isset($indigoMap[$clave])) {
        $indigoMap[$clave] = $sanitizado;
    }
}

echo "AD únicos sanitizados: "     . count($adMap)     . PHP_EOL;
echo "Indigo únicos sanitizados: " . count($indigoMap) . PHP_EOL;

// ============================================================
// 4. COMPARAR
// ============================================================

$coinciden    = []; // clave => [cargo_ad, cargo_indigo]
$soloEnAD     = []; // clave => cargo_ad
$soloEnIndigo = []; // clave => cargo_indigo

foreach ($adMap as $clave => $cargoAD) {
    if (isset($indigoMap[$clave])) {
        $coinciden[$clave] = [
            'cargo_ad'     => $cargoAD,
            'cargo_indigo' => $indigoMap[$clave],
        ];
    } else {
        $soloEnAD[$clave] = $cargoAD;
    }
}

foreach ($indigoMap as $clave => $cargoIndigo) {
    if (!isset($adMap[$clave])) {
        $soloEnIndigo[$clave] = $cargoIndigo;
    }
}

// Ordenar alfabéticamente
asort($coinciden);
asort($soloEnAD);
asort($soloEnIndigo);

echo PHP_EOL;
echo "=== RESULTADOS ===" . PHP_EOL;
echo "Coinciden (en ambos):  " . count($coinciden)    . PHP_EOL;
echo "Solo en AD:            " . count($soloEnAD)      . PHP_EOL;
echo "Solo en Indigo:        " . count($soloEnIndigo)  . PHP_EOL;

// ============================================================
// 5. HELPER ESTILOS EXCEL
// ============================================================

function estiloEncabezado(Spreadsheet $wb, string $sheet, string $rango, string $colorFondo): void
{
    $ws = $wb->getSheetByName($sheet);
    $ws->getStyle($rango)->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorFondo]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
    ]);
    $ws->getRowDimension(1)->setRowHeight(22);
}

function estiloFila(Spreadsheet $wb, string $sheet, string $rango, bool $par): void
{
    $ws = $wb->getSheetByName($sheet);
    $ws->getStyle($rango)->applyFromArray([
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $par ? 'F2F2F2' : 'FFFFFF']],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
}

// ============================================================
// 6. EXCEL 1: CARGOS QUE COINCIDEN
// ============================================================

echo PHP_EOL . "Generando Excel 1: Cargos que coinciden..." . PHP_EOL;

$wb1 = new Spreadsheet();
$wb1->getProperties()
    ->setTitle('Cargos Coincidentes AD vs Indigo')
    ->setCreator('Sistema Certec');

$ws1 = $wb1->getActiveSheet();
$ws1->setTitle('Coincidentes');

// Encabezados
$ws1->setCellValue('A1', '#');
$ws1->setCellValue('B1', 'CARGO EN AD (Sanitizado)');
$ws1->setCellValue('C1', 'CARGO EN INDIGO');
$ws1->setCellValue('D1', 'ESTADO');

$ws1->getColumnDimension('A')->setWidth(6);
$ws1->getColumnDimension('B')->setWidth(55);
$ws1->getColumnDimension('C')->setWidth(55);
$ws1->getColumnDimension('D')->setWidth(18);

estiloEncabezado($wb1, 'Coincidentes', 'A1:D1', '1F6B3A'); // verde oscuro

$fila = 2;
$num  = 1;
foreach ($coinciden as $data) {
    $ws1->setCellValue("A{$fila}", $num);
    $ws1->setCellValue("B{$fila}", $data['cargo_ad']);
    $ws1->setCellValue("C{$fila}", $data['cargo_indigo']);
    $ws1->setCellValue("D{$fila}", 'COINCIDE ✓');

    $ws1->getStyle("D{$fila}")->getFont()->getColor()->setRGB('1F6B3A');
    $ws1->getStyle("D{$fila}")->getFont()->setBold(true);
    estiloFila($wb1, 'Coincidentes', "A{$fila}:D{$fila}", $num % 2 === 0);

    $ws1->getRowDimension($fila)->setRowHeight(18);
    $fila++;
    $num++;
}

// Resumen al final
$fila++;
$ws1->setCellValue("A{$fila}", 'TOTAL');
$ws1->setCellValue("B{$fila}", count($coinciden) . ' cargos coincidentes');
$ws1->getStyle("A{$fila}:D{$fila}")->getFont()->setBold(true);
$ws1->getStyle("A{$fila}:D{$fila}")->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAD6');

$path1 = 'docs/Cargos_Coincidentes_AD_vs_Indigo.xlsx';
IOFactory::createWriter($wb1, 'Xlsx')->save($path1);
echo "  → Guardado: {$path1}" . PHP_EOL;

// ============================================================
// 7. EXCEL 2: CARGOS QUE NO COINCIDEN
// ============================================================

echo "Generando Excel 2: Cargos que NO coinciden..." . PHP_EOL;

$wb2 = new Spreadsheet();
$wb2->getProperties()
    ->setTitle('Cargos No Coincidentes AD vs Indigo')
    ->setCreator('Sistema Certec');

// --- Hoja 1: Solo en AD ---
$ws2a = $wb2->getActiveSheet();
$ws2a->setTitle('Solo en AD');

$ws2a->setCellValue('A1', '#');
$ws2a->setCellValue('B1', 'CARGO EN AD (Sanitizado)');
$ws2a->setCellValue('C1', 'OBSERVACIÓN');

$ws2a->getColumnDimension('A')->setWidth(6);
$ws2a->getColumnDimension('B')->setWidth(60);
$ws2a->getColumnDimension('C')->setWidth(35);

estiloEncabezado($wb2, 'Solo en AD', 'A1:C1', 'C0392B'); // rojo

$fila = 2;
$num  = 1;
foreach ($soloEnAD as $cargo) {
    $ws2a->setCellValue("A{$fila}", $num);
    $ws2a->setCellValue("B{$fila}", $cargo);
    $ws2a->setCellValue("C{$fila}", 'No existe en Indigo');
    estiloFila($wb2, 'Solo en AD', "A{$fila}:C{$fila}", $num % 2 === 0);
    $ws2a->getRowDimension($fila)->setRowHeight(18);
    $fila++;
    $num++;
}

$fila++;
$ws2a->setCellValue("A{$fila}", 'TOTAL');
$ws2a->setCellValue("B{$fila}", count($soloEnAD) . ' cargos solo en AD');
$ws2a->getStyle("A{$fila}:C{$fila}")->getFont()->setBold(true);
$ws2a->getStyle("A{$fila}:C{$fila}")->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FADBD8');

// --- Hoja 2: Solo en Indigo ---
$wb2->createSheet()->setTitle('Solo en Indigo');
$ws2b = $wb2->getSheetByName('Solo en Indigo');

$ws2b->setCellValue('A1', '#');
$ws2b->setCellValue('B1', 'CARGO EN INDIGO');
$ws2b->setCellValue('C1', 'OBSERVACIÓN');

$ws2b->getColumnDimension('A')->setWidth(6);
$ws2b->getColumnDimension('B')->setWidth(60);
$ws2b->getColumnDimension('C')->setWidth(35);

estiloEncabezado($wb2, 'Solo en Indigo', 'A1:C1', 'E67E22'); // naranja

$fila = 2;
$num  = 1;
foreach ($soloEnIndigo as $cargo) {
    $ws2b->setCellValue("A{$fila}", $num);
    $ws2b->setCellValue("B{$fila}", $cargo);
    $ws2b->setCellValue("C{$fila}", 'No existe en AD');
    estiloFila($wb2, 'Solo en Indigo', "A{$fila}:C{$fila}", $num % 2 === 0);
    $ws2b->getRowDimension($fila)->setRowHeight(18);
    $fila++;
    $num++;
}

$fila++;
$ws2b->setCellValue("A{$fila}", 'TOTAL');
$ws2b->setCellValue("B{$fila}", count($soloEnIndigo) . ' cargos solo en Indigo');
$ws2b->getStyle("A{$fila}:C{$fila}")->getFont()->setBold(true);
$ws2b->getStyle("A{$fila}:C{$fila}")->getFill()
    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');

// --- Hoja 3: Resumen ---
$wb2->createSheet()->setTitle('Resumen');
$ws2c = $wb2->getSheetByName('Resumen');

$ws2c->setCellValue('A1', 'RESUMEN COMPARACIÓN CARGOS AD vs INDIGO');
$ws2c->mergeCells('A1:C1');
$ws2c->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C3E50']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$ws2c->getRowDimension(1)->setRowHeight(28);

$resumen = [
    ['Métrica', 'Cantidad', 'Detalle'],
    ['Total registros AD (bruto)',        count($adValores),     'Con duplicados y variantes'],
    ['Total únicos AD (sanitizados)',      count($adMap),         'Después de deduplicar y normalizar'],
    ['Total registros Indigo',             count($indigoValores), 'Registros originales'],
    ['Total únicos Indigo (sanitizados)',  count($indigoMap),     'Después de normalizar'],
    ['', '', ''],
    ['Cargos que COINCIDEN',              count($coinciden),     'Presentes en AD y en Indigo'],
    ['Cargos SOLO en AD',                 count($soloEnAD),      'No encontrados en Indigo'],
    ['Cargos SOLO en Indigo',             count($soloEnIndigo),  'No encontrados en AD'],
];

$colores = [
    3 => 'EAECEE', 4 => 'EAECEE', 5 => 'EAECEE', 6 => 'EAECEE',
    8 => 'D6EAD6', 9 => 'FADBD8', 10 => 'FDEBD0',
];

$fila = 2;
foreach ($resumen as $i => $row) {
    $ws2c->setCellValue("A{$fila}", $row[0]);
    $ws2c->setCellValue("B{$fila}", $row[1]);
    $ws2c->setCellValue("C{$fila}", $row[2]);
    if ($i === 0) {
        $ws2c->getStyle("A{$fila}:C{$fila}")->getFont()->setBold(true);
        $ws2c->getStyle("A{$fila}:C{$fila}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('BDC3C7');
    } elseif (isset($colores[$fila])) {
        $ws2c->getStyle("A{$fila}:C{$fila}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colores[$fila]);
    }
    $ws2c->getRowDimension($fila)->setRowHeight(18);
    $fila++;
}

$ws2c->getColumnDimension('A')->setWidth(40);
$ws2c->getColumnDimension('B')->setWidth(12);
$ws2c->getColumnDimension('C')->setWidth(40);

$wb2->setActiveSheetIndex(0);

$path2 = 'docs/Cargos_No_Coincidentes_AD_vs_Indigo.xlsx';
IOFactory::createWriter($wb2, 'Xlsx')->save($path2);
echo "  → Guardado: {$path2}" . PHP_EOL;

echo PHP_EOL . "✅ Proceso completado exitosamente." . PHP_EOL;
echo "   Archivo 1: {$path1}" . PHP_EOL;
echo "   Archivo 2: {$path2}" . PHP_EOL;
