<?php
/**
 * Sync Cargos: Indigo vs config_cargo en BD
 *
 * 1. Lee cargos de Indigo (fuente de verdad)
 * 2. Compara con config_cargo en BD
 * 3. Inserta los que no existen, relacionados a id_empresa = 1 (Medilaser)
 * 4. Genera reporte Excel con coincidentes y nuevos insertados
 */

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// ============================================================
// CONEXIÓN BD (usa bootstrap de Laravel para leer .env)
// ============================================================
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4";
$pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "✅ Conexión BD exitosa" . PHP_EOL;

// ============================================================
// FUNCIONES SANITIZACIÓN (igual que comparar_cargos.php)
// ============================================================

function arabicToRoman(string $num): string
{
    $map = [
        '10' => 'X', '9' => 'IX', '8' => 'VIII', '7' => 'VII',
        '6'  => 'VI', '5' => 'V',  '4' => 'IV',   '3' => 'III',
        '2'  => 'II', '1' => 'I',
    ];
    return $map[trim($num)] ?? $num;
}

function sanitizarCargo(string $cargo): string
{
    $cargo = trim($cargo);
    $cargo = preg_replace('/\s+/', ' ', $cargo);
    $cargo = preg_replace('/\s*-\s*/', ' ', $cargo);
    $cargo = mb_strtoupper($cargo, 'UTF-8');

    // Arábigos → Romano
    $cargo = preg_replace_callback('/\bTIPO\s+([0-9]+)\b/', function ($m) {
        return 'TIPO ' . arabicToRoman($m[1]);
    }, $cargo);

    // Romano mixed case → uppercase
    $cargo = preg_replace_callback('/\bTIPO\s+([IVXivx]+)\b/', function ($m) {
        return 'TIPO ' . strtoupper($m[1]);
    }, $cargo);

    return trim(preg_replace('/\s+/', ' ', $cargo));
}

function claveComparacion(string $cargo): string
{
    $cargo = sanitizarCargo($cargo);
    $cargo = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargo);
    $cargo = preg_replace('/[^A-Z0-9 ]/', '', $cargo);
    return trim(preg_replace('/\s+/', ' ', $cargo));
}

// ============================================================
// 1. LEER ESTRUCTURA config_cargo
// ============================================================
$cols = $pdo->query("SHOW COLUMNS FROM config_cargo")->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . "Columnas de config_cargo:" . PHP_EOL;
foreach ($cols as $col) {
    echo "  {$col['Field']} | {$col['Type']} | Null:{$col['Null']} | Default:{$col['Default']}" . PHP_EOL;
}

// ============================================================
// 2. LEER CARGOS ACTUALES EN BD
// ============================================================
$stmt = $pdo->query("SELECT id_cargo, nombre_cargo, nivel_jerarquico FROM config_cargo ORDER BY nombre_cargo");
$cargosDB = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo PHP_EOL . "Cargos en BD: " . count($cargosDB) . PHP_EOL;

// Mapa clave => registro BD
$mapaDB = [];
foreach ($cargosDB as $c) {
    $clave = claveComparacion($c['nombre_cargo']);
    $mapaDB[$clave] = $c;
}

// ============================================================
// 3. LEER CARGOS INDIGO (fuente de verdad)
// ============================================================
$indigoRaw = IOFactory::load('docs/Cargos Indigo.xlsx')->getActiveSheet()->toArray();
$indigoValores = array_filter(array_map(fn($r) => trim($r[0] ?? ''), $indigoRaw));

$indigoMap = [];
foreach ($indigoValores as $cargo) {
    $clave = claveComparacion($cargo);
    if ($clave !== '' && !isset($indigoMap[$clave])) {
        $indigoMap[$clave] = sanitizarCargo($cargo);
    }
}

echo "Cargos Indigo únicos: " . count($indigoMap) . PHP_EOL;

// ============================================================
// 4. COMPARAR Y CLASIFICAR
// ============================================================
$yaExisten  = []; // clave => ['nombre' => ..., 'id_cargo' => ..., 'nivel' => ...]
$porInsertar = []; // clave => nombre_sanitizado

foreach ($indigoMap as $clave => $nombre) {
    if (isset($mapaDB[$clave])) {
        $yaExisten[$clave] = [
            'nombre'    => $nombre,
            'id_cargo'  => $mapaDB[$clave]['id_cargo'],
            'nivel'     => $mapaDB[$clave]['nivel_jerarquico'],
        ];
    } else {
        $porInsertar[$clave] = $nombre;
    }
}

echo PHP_EOL . "=== COMPARACIÓN INDIGO vs BD ===" . PHP_EOL;
echo "Ya existen en BD:  " . count($yaExisten)   . PHP_EOL;
echo "Por insertar:      " . count($porInsertar) . PHP_EOL;

// ============================================================
// 5. VERIFICAR / AGREGAR COLUMNA id_empresa
// ============================================================
$tieneIdEmpresa = false;
foreach ($cols as $col) {
    if ($col['Field'] === 'id_empresa') {
        $tieneIdEmpresa = true;
        break;
    }
}

if (!$tieneIdEmpresa) {
    echo PHP_EOL . "Agregando columna id_empresa a config_cargo..." . PHP_EOL;
    $pdo->exec("ALTER TABLE config_cargo 
        ADD COLUMN id_empresa BIGINT UNSIGNED NULL 
        COMMENT 'FK a ent_empresas' 
        AFTER nivel_jerarquico,
        ADD INDEX idx_id_empresa (id_empresa)");
    echo "  ✅ Columna id_empresa agregada" . PHP_EOL;
} else {
    echo PHP_EOL . "  ℹ️  Columna id_empresa ya existe" . PHP_EOL;
}

// ============================================================
// 6. INSERTAR CARGOS NUEVOS (id_empresa = 1, nivel = 3 por defecto)
// ============================================================
$insertados = [];

if (!empty($porInsertar)) {
    echo PHP_EOL . "Insertando " . count($porInsertar) . " cargos nuevos..." . PHP_EOL;

    $stmtInsert = $pdo->prepare(
        "INSERT INTO config_cargo (nombre_cargo, nivel_jerarquico, id_empresa, estado) 
         VALUES (:nombre, :nivel, :empresa, 1)"
    );

    foreach ($porInsertar as $clave => $nombre) {
        $stmtInsert->execute([
            ':nombre'  => $nombre,
            ':nivel'   => 3, // Operativo por defecto
            ':empresa' => 1, // Medilaser
        ]);
        $insertados[$clave] = [
            'nombre'   => $nombre,
            'id_cargo' => $pdo->lastInsertId(),
        ];
    }

    echo "  ✅ " . count($insertados) . " cargos insertados" . PHP_EOL;
}

// ============================================================
// 7. ACTUALIZAR id_empresa = 1 EN CARGOS QUE YA EXISTEN
//    (solo si id_empresa es NULL para no pisar datos)
// ============================================================
$actualizados = $pdo->exec(
    "UPDATE config_cargo SET id_empresa = 1 
     WHERE id_empresa IS NULL AND estado = 1"
);
echo PHP_EOL . "Cargos actualizados con id_empresa=1: {$actualizados}" . PHP_EOL;

// ============================================================
// 8. GENERAR EXCEL DE REPORTE
// ============================================================
echo PHP_EOL . "Generando Excel de reporte..." . PHP_EOL;

$wb = new Spreadsheet();
$wb->getProperties()->setTitle('Sync Cargos Indigo vs BD');

// --- Hoja 1: Ya existían ---
$ws1 = $wb->getActiveSheet()->setTitle('Ya en BD');
$ws1 = $wb->getSheetByName('Ya en BD');

$ws1->setCellValue('A1', '#');
$ws1->setCellValue('B1', 'CARGO (Indigo)');
$ws1->setCellValue('C1', 'ID en BD');
$ws1->setCellValue('D1', 'NIVEL JERÁRQUICO');
$ws1->setCellValue('E1', 'ID EMPRESA');
$ws1->setCellValue('F1', 'ESTADO');

foreach (['A'=>6,'B'=>50,'C'=>10,'D'=>20,'E'=>12,'F'=>18] as $col => $w) {
    $ws1->getColumnDimension($col)->setWidth($w);
}

$ws1->getStyle('A1:F1')->applyFromArray([
    'font' => ['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>11],
    'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1F6B3A']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CCCCCC']]],
]);
$ws1->getRowDimension(1)->setRowHeight(22);

$fila = 2; $num = 1;
foreach ($yaExisten as $data) {
    $ws1->setCellValue("A{$fila}", $num);
    $ws1->setCellValue("B{$fila}", $data['nombre']);
    $ws1->setCellValue("C{$fila}", $data['id_cargo']);
    $ws1->setCellValue("D{$fila}", $data['nivel']);
    $ws1->setCellValue("E{$fila}", 1);
    $ws1->setCellValue("F{$fila}", 'YA EXISTÍA ✓');
    $ws1->getStyle("F{$fila}")->getFont()->getColor()->setRGB('1F6B3A');
    $ws1->getStyle("F{$fila}")->getFont()->setBold(true);
    $bg = $num % 2 === 0 ? 'F2F2F2' : 'FFFFFF';
    $ws1->getStyle("A{$fila}:F{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
    $ws1->getRowDimension($fila)->setRowHeight(18);
    $fila++; $num++;
}

// Total
$fila++;
$ws1->setCellValue("A{$fila}", 'TOTAL');
$ws1->setCellValue("B{$fila}", count($yaExisten) . ' cargos ya existían en BD');
$ws1->getStyle("A{$fila}:F{$fila}")->getFont()->setBold(true);
$ws1->getStyle("A{$fila}:F{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAD6');

// --- Hoja 2: Insertados ---
$wb->createSheet()->setTitle('Insertados Nuevos');
$ws2 = $wb->getSheetByName('Insertados Nuevos');

$ws2->setCellValue('A1', '#');
$ws2->setCellValue('B1', 'CARGO INSERTADO');
$ws2->setCellValue('C1', 'ID ASIGNADO');
$ws2->setCellValue('D1', 'NIVEL JERÁRQUICO');
$ws2->setCellValue('E1', 'ID EMPRESA');
$ws2->setCellValue('F1', 'ESTADO');

foreach (['A'=>6,'B'=>50,'C'=>12,'D'=>20,'E'=>12,'F'=>18] as $col => $w) {
    $ws2->getColumnDimension($col)->setWidth($w);
}

$ws2->getStyle('A1:F1')->applyFromArray([
    'font' => ['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>11],
    'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1A5276']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'CCCCCC']]],
]);
$ws2->getRowDimension(1)->setRowHeight(22);

$fila = 2; $num = 1;
foreach ($insertados as $data) {
    $ws2->setCellValue("A{$fila}", $num);
    $ws2->setCellValue("B{$fila}", $data['nombre']);
    $ws2->setCellValue("C{$fila}", $data['id_cargo']);
    $ws2->setCellValue("D{$fila}", 3);
    $ws2->setCellValue("E{$fila}", 1);
    $ws2->setCellValue("F{$fila}", 'INSERTADO NUEVO ✓');
    $ws2->getStyle("F{$fila}")->getFont()->getColor()->setRGB('1A5276');
    $ws2->getStyle("F{$fila}")->getFont()->setBold(true);
    $bg = $num % 2 === 0 ? 'EBF5FB' : 'FFFFFF';
    $ws2->getStyle("A{$fila}:F{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
    $ws2->getRowDimension($fila)->setRowHeight(18);
    $fila++; $num++;
}

if (empty($insertados)) {
    $ws2->setCellValue("A{$fila}", 'Sin registros nuevos para insertar');
    $fila++;
}

$fila++;
$ws2->setCellValue("A{$fila}", 'TOTAL');
$ws2->setCellValue("B{$fila}", count($insertados) . ' cargos nuevos insertados');
$ws2->getStyle("A{$fila}:F{$fila}")->getFont()->setBold(true);
$ws2->getStyle("A{$fila}:F{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');

// --- Hoja 3: Resumen ---
$wb->createSheet()->setTitle('Resumen');
$ws3 = $wb->getSheetByName('Resumen');

$ws3->setCellValue('A1', 'SYNC CARGOS INDIGO → config_cargo (Medilaser id=1)');
$ws3->mergeCells('A1:C1');
$ws3->getStyle('A1')->applyFromArray([
    'font'      => ['bold'=>true,'size'=>13,'color'=>['rgb'=>'FFFFFF']],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'2C3E50']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
]);
$ws3->getRowDimension(1)->setRowHeight(26);

$resumen = [
    ['Métrica',                        'Cantidad'],
    ['Cargos en Indigo (únicos)',       count($indigoMap)],
    ['Cargos en BD antes del sync',     count($cargosDB)],
    ['',                                ''],
    ['Ya existían (sin cambio)',        count($yaExisten)],
    ['Insertados nuevos',               count($insertados)],
    ['Actualizados con id_empresa=1',   $actualizados],
    ['',                                ''],
    ['Total en BD después del sync',    count($cargosDB) + count($insertados)],
];

$coloresFila = [3=>'EAECEE',4=>'EAECEE',6=>'D6EAD6',7=>'D6EAF8',8=>'FEF9E7',10=>'D5F5E3'];

$fila = 2;
foreach ($resumen as $i => $row) {
    $ws3->setCellValue("A{$fila}", $row[0]);
    $ws3->setCellValue("B{$fila}", $row[1]);
    if ($i === 0) {
        $ws3->getStyle("A{$fila}:B{$fila}")->getFont()->setBold(true);
        $ws3->getStyle("A{$fila}:B{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('BDC3C7');
    } elseif (isset($coloresFila[$fila])) {
        $ws3->getStyle("A{$fila}:B{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($coloresFila[$fila]);
    }
    $ws3->getRowDimension($fila)->setRowHeight(18);
    $fila++;
}

$ws3->getColumnDimension('A')->setWidth(38);
$ws3->getColumnDimension('B')->setWidth(14);

$wb->setActiveSheetIndex(0);

$path = 'docs/Sync_Cargos_Indigo_BD.xlsx';
IOFactory::createWriter($wb, 'Xlsx')->save($path);
echo "  → Guardado: {$path}" . PHP_EOL;
echo PHP_EOL . "✅ Sync completado." . PHP_EOL;
