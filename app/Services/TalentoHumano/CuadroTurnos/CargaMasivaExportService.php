<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

use App\Models\TalentoHumano\CuadroTurnos\CtPlantilla;
use App\Models\TalentoHumano\CuadroTurnos\CtAsignacion;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CargaMasivaExportService
{
    /**
     * Genera el archivo Excel con formato para carga masiva.
     *
     * @param int $idUnidad ID de la unidad funcional
     * @param int $anio A+¦o
     * @param int $mes Mes (1-12)
     * @return Spreadsheet
     */
    public function generarFormato(int $idUnidad, int $anio, int $mes): Spreadsheet
    {
        // Obtener datos
        $unidad = DB::table('config_unidades_funcionales')->find($idUnidad);
        $idEmpresa = $unidad->id_empresa ?? null;
        $empleados = $this->obtenerEmpleados($idUnidad);
        $plantillas = $this->obtenerPlantillas($idEmpresa);
        $diasEnMes = Carbon::create($anio, $mes, 1)->daysInMonth;

        $spreadsheet = new Spreadsheet();

        // Hoja 1: Llenado r+ípido
        $this->crearHojaLlenadoRapido($spreadsheet, $empleados, $plantillas, $unidad, $anio, $mes);

        // Hoja 2: Detalle por d+¡a
        $this->crearHojaDetalleDia($spreadsheet, $empleados, $plantillas, $unidad, $anio, $mes, $diasEnMes);

        // Hoja 3: C+¦digos v+ílidos (referencia)
        $this->crearHojaCodigosValidos($spreadsheet, $plantillas);

        // Activar hoja 1 por defecto
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Hoja 1: Llenado r+ípido ÔÇö una columna "TURNO TODO EL MES" por empleado.
     */
    private function crearHojaLlenadoRapido(
        Spreadsheet $spreadsheet,
        array $empleados,
        array $plantillas,
        object $unidad,
        int $anio,
        int $mes
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Llenado R+ípido');

        $nombreMes = Carbon::create($anio, $mes, 1)->translatedFormat('F Y');

        // Header informativo
        $sheet->setCellValue('A1', 'CARGA MASIVA DE TURNOS');
        $sheet->setCellValue('A2', "Unidad: {$unidad->nombre}");
        $sheet->setCellValue('A3', "Per+¡odo: {$nombreMes}");
        $sheet->setCellValue('A4', 'Instrucciones: Escriba el C+ôDIGO de plantilla en la columna "TURNO TODO EL MES".');
        $sheet->setCellValue('A5', 'Use "D" para d+¡a de descanso. Deje vac+¡o para no asignar.');

        // Estilo header
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A5')->getFont()->setItalic(true);

        // Encabezados de tabla (fila 7)
        $row = 7;
        $sheet->setCellValue("A{$row}", 'EMPLEADO');
        $sheet->setCellValue("B{$row}", 'ID');
        $sheet->setCellValue("C{$row}", 'TURNO TODO EL MES');
        $sheet->setCellValue("D{$row}", 'TRABAJA S+üBADO');
        $sheet->setCellValue("E{$row}", 'TRABAJA DOMINGO');
        $sheet->setCellValue("F{$row}", 'TRABAJA FESTIVOS');

        // Estilo encabezados
        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6366F1']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Datos de empleados
        $codigosValidos = array_merge(
            array_column($plantillas, 'codigo'),
            ['D'] // D = descanso
        );
        $listaValidacion = '"' . implode(',', $codigosValidos) . '"';
        $listaSiNo = '"S,N"';

        foreach ($empleados as $i => $emp) {
            $dataRow = $row + 1 + $i;
            $sheet->setCellValue("A{$dataRow}", $emp['nombre']);
            $sheet->setCellValue("B{$dataRow}", $emp['id']);
            $sheet->setCellValue("C{$dataRow}", ''); // Turno
            $sheet->setCellValue("D{$dataRow}", 'N'); // S+íbado por defecto No
            $sheet->setCellValue("E{$dataRow}", 'N'); // Domingo por defecto No
            $sheet->setCellValue("F{$dataRow}", 'N'); // Festivos por defecto No

            // Validaci+¦n dropdown en columna C (c+¦digo plantilla)
            $validation = $sheet->getCell("C{$dataRow}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_WARNING);
            $validation->setAllowBlank(true);
            $validation->setShowDropDown(true);
            $validation->setFormula1($listaValidacion);
            $validation->setErrorTitle('C+¦digo inv+ílido');
            $validation->setError('Use un c+¦digo de la hoja "C+¦digos V+ílidos"');

            // Validaci+¦n S/N para s+íbado, domingo, festivos
            foreach (['D', 'E', 'F'] as $col) {
                $val = $sheet->getCell("{$col}{$dataRow}")->getDataValidation();
                $val->setType(DataValidation::TYPE_LIST);
                $val->setErrorStyle(DataValidation::STYLE_STOP);
                $val->setAllowBlank(false);
                $val->setShowDropDown(true);
                $val->setFormula1($listaSiNo);
            }
        }

        // Ocultar columna ID (B) ÔÇö es para referencia interna
        $sheet->getColumnDimension('B')->setVisible(false);

        // Auto-ancho
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(18);

        // Metadata oculta (fila 1 columna Z) para identificar el archivo al importar
        $sheet->setCellValue('Z1', $unidad->id);
        $sheet->setCellValue('Z2', $anio);
        $sheet->setCellValue('Z3', $mes);
        $sheet->getColumnDimension('Z')->setVisible(false);
    }

    /**
     * Hoja 2: Detalle por d+¡a ÔÇö matriz empleados +ù d+¡as del mes.
     */
    private function crearHojaDetalleDia(
        Spreadsheet $spreadsheet,
        array $empleados,
        array $plantillas,
        object $unidad,
        int $anio,
        int $mes,
        int $diasEnMes
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detalle por D+¡a');

        $nombreMes = Carbon::create($anio, $mes, 1)->translatedFormat('F Y');

        // Header
        $sheet->setCellValue('A1', "DETALLE POR D+ìA ÔÇö {$unidad->nombre} ÔÇö {$nombreMes}");
        $sheet->setCellValue('A2', 'Escriba el c+¦digo de plantilla en cada d+¡a. "D" = descanso. Vac+¡o = sin turno.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getFont()->setItalic(true);

        // Encabezados (fila 4)
        $headerRow = 4;
        $sheet->setCellValue("A{$headerRow}", 'EMPLEADO');
        $sheet->setCellValue("B{$headerRow}", 'ID');

        // Columnas de d+¡as: C = d+¡a 1, D = d+¡a 2, etc.
        for ($d = 1; $d <= $diasEnMes; $d++) {
            $col = $this->numToCol($d + 1); // C=d+¡a1, D=d+¡a2, ...
            $fecha = Carbon::create($anio, $mes, $d);
            $diaSemana = mb_substr($fecha->translatedFormat('D'), 0, 3);
            $sheet->setCellValue("{$col}{$headerRow}", "{$d}\n{$diaSemana}");

            // Color para domingos
            if ($fecha->isSunday()) {
                $sheet->getStyle("{$col}{$headerRow}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEE2E2']],
                ]);
            }
        }

        // Estilo encabezados
        $lastCol = $this->numToCol($diasEnMes + 1);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        // Validaci+¦n dropdown
        $codigosValidos = array_merge(array_column($plantillas, 'codigo'), ['D']);
        $listaValidacion = '"' . implode(',', $codigosValidos) . '"';

        // Datos empleados
        foreach ($empleados as $i => $emp) {
            $dataRow = $headerRow + 1 + $i;
            $sheet->setCellValue("A{$dataRow}", $emp['nombre']);
            $sheet->setCellValue("B{$dataRow}", $emp['id']);

            // Validaci+¦n en cada celda de d+¡a
            for ($d = 1; $d <= $diasEnMes; $d++) {
                $col = $this->numToCol($d + 1);
                $validation = $sheet->getCell("{$col}{$dataRow}")->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_WARNING);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setFormula1($listaValidacion);
            }
        }

        // Ocultar columna B (ID)
        $sheet->getColumnDimension('B')->setVisible(false);
        $sheet->getColumnDimension('A')->setAutoSize(true);

        // Ancho columnas de d+¡as
        for ($d = 1; $d <= $diasEnMes; $d++) {
            $col = $this->numToCol($d + 1);
            $sheet->getColumnDimension($col)->setWidth(6);
        }

        // Metadata oculta
        $sheet->setCellValue('AK1', $unidad->id);
        $sheet->setCellValue('AK2', $anio);
        $sheet->setCellValue('AK3', $mes);
        $sheet->getColumnDimension('AK')->setVisible(false);
    }

    /**
     * Hoja 3: C+¦digos v+ílidos ÔÇö referencia de plantillas disponibles.
     */
    private function crearHojaCodigosValidos(Spreadsheet $spreadsheet, array $plantillas): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('C+¦digos V+ílidos');

        // Header
        $sheet->setCellValue('A1', 'C+ôDIGOS DE PLANTILLA DISPONIBLES');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $sheet->setCellValue('A2', 'Use estos c+¦digos en las hojas "Llenado R+ípido" o "Detalle por D+¡a".');
        $sheet->setCellValue('A3', 'El c+¦digo "D" siempre est+í disponible para marcar DESCANSO.');
        $sheet->getStyle('A2:A3')->getFont()->setItalic(true);

        // Encabezados tabla
        $row = 5;
        $sheet->setCellValue("A{$row}", 'C+ôDIGO');
        $sheet->setCellValue("B{$row}", 'NOMBRE');
        $sheet->setCellValue("C{$row}", 'HORARIO');
        $sheet->setCellValue("D{$row}", 'DURACI+ôN');
        $sheet->setCellValue("E{$row}", 'NOCTURNO');

        $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6366F1']],
        ]);

        // Descanso siempre disponible
        $row++;
        $sheet->setCellValue("A{$row}", 'D');
        $sheet->setCellValue("B{$row}", 'Descanso');
        $sheet->setCellValue("C{$row}", 'ÔÇö');
        $sheet->setCellValue("D{$row}", 'ÔÇö');
        $sheet->setCellValue("E{$row}", 'No');
        $sheet->getStyle("A{$row}:E{$row}")->getFont()->setItalic(true);

        // Plantillas
        foreach ($plantillas as $p) {
            $row++;
            $horario = substr($p['hora_inicio'], 0, 5) . ' - ' . substr($p['hora_fin'], 0, 5);
            if (!empty($p['hora_inicio_2']) && !empty($p['hora_fin_2'])) {
                $horario .= ' | ' . substr($p['hora_inicio_2'], 0, 5) . ' - ' . substr($p['hora_fin_2'], 0, 5);
            }

            $sheet->setCellValue("A{$row}", $p['codigo']);
            $sheet->setCellValue("B{$row}", $p['nombre']);
            $sheet->setCellValue("C{$row}", $horario);
            $sheet->setCellValue("D{$row}", ($p['duracion_horas'] ?? '?') . 'h');
            $sheet->setCellValue("E{$row}", $p['es_nocturno'] ? 'S+¡' : 'No');
        }

        // Auto-ancho
        foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Obtener empleados de la unidad funcional.
     * Usa la misma l+¦gica que UnidadFuncionalController@empleados:
     * tabla config_unidades_fun_usuarios JOIN config_person_tercero
     */
    private function obtenerEmpleados(int $idUnidad): array
    {
        // Fuente principal: config_unidades_fun_usuarios (misma que el endpoint /empleados)
        try {
            $empleados = DB::table('config_unidades_fun_usuarios as cfu')
                ->join('config_person_tercero as t', 't.id', '=', 'cfu.id_user')
                ->where('cfu.id_unidad_funcional', $idUnidad)
                ->select('cfu.id_user as id', 't.nombre', 't.email')
                ->orderBy('t.nombre')
                ->get()
                ->map(fn($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'email' => $e->email])
                ->toArray();

            if (!empty($empleados)) return $empleados;
        } catch (\Exception $e) {
            // Si la tabla no existe, intentar fallback
        }

        // Fallback 1: config_unidades_fun_tercero (otra posible relaci+¦n)
        try {
            $empleados = DB::table('config_unidades_fun_tercero as ut')
                ->join('config_person_tercero as t', 't.id', '=', 'ut.id_tercero')
                ->where('ut.id_unidad_funcional', $idUnidad)
                ->where('ut.estado', true)
                ->select('t.id', 't.nombre', 't.email')
                ->orderBy('t.nombre')
                ->get()
                ->map(fn($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'email' => $e->email])
                ->toArray();

            if (!empty($empleados)) return $empleados;
        } catch (\Exception $e) {
            // Tabla no existe
        }

        // Fallback 2: empleados que ya tengan asignaciones en cuadros de esta unidad
        $empleados = DB::table('humtal_ct_asignacion as a')
            ->join('humtal_ct_cuadro as c', 'c.id', '=', 'a.id_cuadro')
            ->join('config_person_tercero as t', 't.id', '=', 'a.id_empleado')
            ->where('c.id_unidad_funcional', $idUnidad)
            ->select('t.id', 't.nombre', 't.email')
            ->distinct()
            ->orderBy('t.nombre')
            ->get()
            ->map(fn($e) => ['id' => $e->id, 'nombre' => $e->nombre, 'email' => $e->email])
            ->toArray();

        return $empleados;
    }

    /**
     * Obtener plantillas activas de la empresa (o globales).
     */
    private function obtenerPlantillas(?int $idEmpresa): array
    {
        $query = CtPlantilla::where('estado', true);

        if ($idEmpresa) {
            $query->where(function ($q) use ($idEmpresa) {
                $q->where('id_empresa', $idEmpresa)
                  ->orWhereNull('id_empresa');
            });
        }

        return $query->orderBy('codigo')->get()->toArray();
    }

    /**
     * Convierte n+¦mero de columna (0-indexed) a letra Excel.
     * 0=A, 1=B, 2=C, ..., 25=Z, 26=AA, etc.
     */
    private function numToCol(int $num): string
    {
        $col = '';
        while ($num >= 0) {
            $col = chr(65 + ($num % 26)) . $col;
            $num = intdiv($num, 26) - 1;
        }
        return $col;
    }
}
