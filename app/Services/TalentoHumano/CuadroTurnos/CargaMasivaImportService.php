<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

use App\Models\TalentoHumano\CuadroTurnos\CtPlantilla;
use App\Models\TalentoHumano\CuadroTurnos\CtAsignacion;
use App\Models\TalentoHumano\CuadroTurnos\CtCuadro;
use App\Models\TalentoHumano\CuadroTurnos\CtFestivo;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CargaMasivaImportService
{
    /**
     * Procesa un archivo Excel de carga masiva de turnos.
     *
     * L+¦gica:
     *   1. Lee metadata oculta (id_unidad, anio, mes)
     *   2. Lee Hoja 1 "Llenado R+ípido" ÔÇö turno completo para todo el mes
     *   3. Lee Hoja 2 "Detalle por D+¡a" ÔÇö turno espec+¡fico por cada d+¡a
     *   4. Hoja 2 tiene prioridad si ambas tienen datos para el mismo empleado
     *   5. Genera asignaciones con updateOrCreate
     *
     * @param string $filePath Ruta al archivo subido
     * @param int $idUnidad ID de unidad funcional (del request, para validar)
     * @param int $anio A+¦o (del request)
     * @param int $mes Mes (del request)
     * @return array Resultado con exitosas, errores, totales
     */
    public function importar(string $filePath, int $idUnidad, int $anio, int $mes): array
    {
        $spreadsheet = IOFactory::load($filePath);

        // Obtener plantillas v+ílidas para la empresa de esta unidad
        $unidad = DB::table('config_unidades_funcionales')->find($idUnidad);
        $idEmpresa = $unidad->id_empresa ?? null;
        $plantillasMap = $this->obtenerMapaPlantillas($idEmpresa);

        // Obtener/crear cuadro para el mes
        $idCuadro = $this->obtenerOCrearCuadro($idUnidad, $anio, $mes);

        if (!$idCuadro) {
            return [
                'exitosas' => 0,
                'errores' => [['fila' => 0, 'mensaje' => 'No se pudo obtener/crear el cuadro para este per+¡odo.']],
                'total' => 0,
            ];
        }

        // Cargar festivos del mes
        $festivos = $this->obtenerFestivosMes($anio, $mes);

        $exitosas = 0;
        $errores = [];
        $empleadosProcesados = []; // Para controlar prioridad hoja 2 > hoja 1

        // Obtener hojas
        $sheetRapido = $this->getSheet($spreadsheet, 0);
        $sheetDetalle = $this->getSheet($spreadsheet, 1);

        // ÔöÇÔöÇÔöÇ Detectar si ambas hojas tienen datos para los mismos empleados ÔöÇÔöÇÔöÇ
        $empleadosHoja1 = $this->detectarEmpleadosConDatos($sheetRapido, 'rapida');
        $empleadosHoja2 = $this->detectarEmpleadosConDatos($sheetDetalle, 'detalle');
        $duplicados = array_intersect($empleadosHoja1, $empleadosHoja2);

        if (!empty($duplicados)) {
            return [
                'exitosas' => 0,
                'errores' => [[
                    'fila' => 0,
                    'mensaje' => 'Se detectaron empleados con datos en ambas hojas ("Llenado R+ípido" y "Detalle por D+¡a"). Debe usar solo una hoja por empleado. Empleados duplicados: ' . count($duplicados),
                ]],
                'total' => 0,
            ];
        }

        // ÔöÇÔöÇÔöÇ HOJA 2: Detalle por D+¡a (tiene prioridad) ÔöÇÔöÇÔöÇ
        if ($sheetDetalle) {
            $resultado = $this->procesarHojaDetalle(
                $sheetDetalle, $plantillasMap, $idCuadro, $anio, $mes, $festivos
            );
            $exitosas += $resultado['exitosas'];
            $errores = array_merge($errores, $resultado['errores']);
            $empleadosProcesados = $resultado['empleados_procesados'];
        }

        // ÔöÇÔöÇÔöÇ HOJA 1: Llenado R+ípido (solo para empleados no procesados en hoja 2) ÔöÇÔöÇÔöÇ
        if ($sheetRapido) {
            $resultado = $this->procesarHojaRapida(
                $sheetRapido, $plantillasMap, $idCuadro, $anio, $mes, $festivos, $empleadosProcesados
            );
            $exitosas += $resultado['exitosas'];
            $errores = array_merge($errores, $resultado['errores']);
        }

        Log::info('Carga masiva completada', [
            'id_unidad' => $idUnidad,
            'anio' => $anio,
            'mes' => $mes,
            'exitosas' => $exitosas,
            'errores' => count($errores),
        ]);

        return [
            'exitosas' => $exitosas,
            'errores' => $errores,
            'total' => $exitosas + count($errores),
        ];
    }

    /**
     * Procesa Hoja 1: Llenado R+ípido.
     * Columna A = nombre, B = ID empleado, C = c+¦digo plantilla, D = s+íbado, E = domingo, F = festivos.
     */
    private function procesarHojaRapida(
        $sheet,
        array $plantillasMap,
        int $idCuadro,
        int $anio,
        int $mes,
        array $festivos,
        array $empleadosYaProcesados
    ): array {
        $exitosas = 0;
        $errores = [];
        $diasEnMes = Carbon::create($anio, $mes, 1)->daysInMonth;

        // Datos empiezan en fila 8 (fila 7 = encabezado)
        $highestRow = $sheet->getHighestRow();

        for ($row = 8; $row <= $highestRow; $row++) {
            $idEmpleado = (int) $sheet->getCell("B{$row}")->getValue();
            $codigo = trim((string) $sheet->getCell("C{$row}")->getValue());

            if (!$idEmpleado || empty($codigo)) continue;

            // Si ya fue procesado en hoja 2, saltar
            if (in_array($idEmpleado, $empleadosYaProcesados)) continue;

            // Leer configuraci+¦n de s+íbado/domingo/festivos
            $trabajaSabado = strtoupper(trim((string) $sheet->getCell("D{$row}")->getValue())) === 'S';
            $trabajaDomingo = strtoupper(trim((string) $sheet->getCell("E{$row}")->getValue())) === 'S';
            $trabajaFestivos = strtoupper(trim((string) $sheet->getCell("F{$row}")->getValue())) === 'S';

            // Validar c+¦digo
            $esDescanso = strtoupper($codigo) === 'D';
            $plantilla = null;

            if (!$esDescanso) {
                $plantilla = $plantillasMap[strtoupper($codigo)] ?? null;
                if (!$plantilla) {
                    $errores[] = [
                        'fila' => $row,
                        'mensaje' => "C+¦digo '{$codigo}' no es v+ílido para esta empresa.",
                    ];
                    continue;
                }
            }

            // Generar asignaci+¦n para cada d+¡a del mes
            for ($d = 1; $d <= $diasEnMes; $d++) {
                $fecha = Carbon::create($anio, $mes, $d);

                // Saltar s+íbados si no trabaja s+íbado
                if ($fecha->isSaturday() && !$trabajaSabado) continue;

                // Saltar domingos si no trabaja domingo
                if ($fecha->isSunday() && !$trabajaDomingo) continue;

                // Saltar festivos si no trabaja festivos
                if (!$trabajaFestivos && in_array($fecha->toDateString(), $festivos)) continue;

                try {
                    CtAsignacion::updateOrCreate(
                        [
                            'id_cuadro' => $idCuadro,
                            'id_empleado' => $idEmpleado,
                            'fecha' => $fecha->toDateString(),
                        ],
                        [
                            'id_plantilla' => $esDescanso ? null : $plantilla['id'],
                            'es_descanso' => $esDescanso,
                            'es_festivo' => $fecha->isSunday() || in_array($fecha->toDateString(), $festivos),
                            'observacion' => 'Carga masiva',
                        ]
                    );
                    $exitosas++;
                } catch (\Exception $e) {
                    $errores[] = [
                        'fila' => $row,
                        'mensaje' => "Error d+¡a {$d}: " . $e->getMessage(),
                    ];
                }
            }
        }

        return ['exitosas' => $exitosas, 'errores' => $errores];
    }

    /**
     * Procesa Hoja 2: Detalle por D+¡a.
     * Fila 4 = encabezados, A = nombre, B = ID, C en adelante = d+¡as 1,2,3...
     */
    private function procesarHojaDetalle(
        $sheet,
        array $plantillasMap,
        int $idCuadro,
        int $anio,
        int $mes,
        array $festivos
    ): array {
        $exitosas = 0;
        $errores = [];
        $empleadosProcesados = [];
        $diasEnMes = Carbon::create($anio, $mes, 1)->daysInMonth;

        // Datos empiezan en fila 5 (fila 4 = encabezado)
        $highestRow = $sheet->getHighestRow();

        for ($row = 5; $row <= $highestRow; $row++) {
            $idEmpleado = (int) $sheet->getCell("B{$row}")->getValue();
            if (!$idEmpleado) continue;

            $tieneAlgunDato = false;

            for ($d = 1; $d <= $diasEnMes; $d++) {
                // Columna C = d+¡a 1, D = d+¡a 2, etc.
                $colIndex = $d + 1; // 0=A, 1=B, 2=C ÔåÆ d+¡a 1 en col index 2
                $col = $this->numToCol($colIndex);
                $codigo = trim((string) $sheet->getCell("{$col}{$row}")->getValue());

                if (empty($codigo)) continue;

                $tieneAlgunDato = true;
                $fecha = Carbon::create($anio, $mes, $d);
                $esDescanso = strtoupper($codigo) === 'D';
                $plantilla = null;

                if (!$esDescanso) {
                    $plantilla = $plantillasMap[strtoupper($codigo)] ?? null;
                    if (!$plantilla) {
                        $errores[] = [
                            'fila' => $row,
                            'mensaje' => "D+¡a {$d}: c+¦digo '{$codigo}' no v+ílido.",
                        ];
                        continue;
                    }
                }

                try {
                    CtAsignacion::updateOrCreate(
                        [
                            'id_cuadro' => $idCuadro,
                            'id_empleado' => $idEmpleado,
                            'fecha' => $fecha->toDateString(),
                        ],
                        [
                            'id_plantilla' => $esDescanso ? null : $plantilla['id'],
                            'es_descanso' => $esDescanso,
                            'es_festivo' => $fecha->isSunday() || in_array($fecha->toDateString(), $festivos),
                            'observacion' => 'Carga masiva',
                        ]
                    );
                    $exitosas++;
                } catch (\Exception $e) {
                    $errores[] = [
                        'fila' => $row,
                        'mensaje' => "D+¡a {$d}: " . $e->getMessage(),
                    ];
                }
            }

            // Marcar como procesado solo si ten+¡a datos
            if ($tieneAlgunDato) {
                $empleadosProcesados[] = $idEmpleado;
            }
        }

        return [
            'exitosas' => $exitosas,
            'errores' => $errores,
            'empleados_procesados' => $empleadosProcesados,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Obtiene mapa de plantillas: [CODIGO => {id, codigo, nombre, ...}]
     */
    private function obtenerMapaPlantillas(?int $idEmpresa): array
    {
        $query = CtPlantilla::where('estado', true);

        if ($idEmpresa) {
            $query->where(function ($q) use ($idEmpresa) {
                $q->where('id_empresa', $idEmpresa)
                  ->orWhereNull('id_empresa');
            });
        }

        $plantillas = $query->get()->toArray();
        $mapa = [];
        foreach ($plantillas as $p) {
            $mapa[strtoupper($p['codigo'])] = $p;
        }
        return $mapa;
    }

    /**
     * Obtiene o crea el cuadro mensual para la unidad funcional.
     */
    private function obtenerOCrearCuadro(int $idUnidad, int $anio, int $mes): ?int
    {
        $cuadro = CtCuadro::where('id_unidad_funcional', $idUnidad)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->first();

        if ($cuadro) return $cuadro->id;

        $cuadro = CtCuadro::create([
            'id_unidad_funcional' => $idUnidad,
            'anio' => $anio,
            'mes' => $mes,
            'estado' => 'creado',
            'creado_por' => auth()->id(),
        ]);

        return $cuadro->id;
    }

    /**
     * Obtiene festivos del mes como array de strings YYYY-MM-DD.
     */
    private function obtenerFestivosMes(int $anio, int $mes): array
    {
        $inicio = Carbon::create($anio, $mes, 1)->startOfMonth()->toDateString();
        $fin = Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString();

        return CtFestivo::where('estado', true)
            ->whereBetween('fecha', [$inicio, $fin])
            ->pluck('fecha')
            ->map(fn($f) => Carbon::parse($f)->toDateString())
            ->toArray();
    }

    /**
     * Obtiene una hoja por +¡ndice de forma segura.
     */
    private function getSheet($spreadsheet, int $index)
    {
        try {
            return $spreadsheet->getSheet($index);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Detecta qu+® empleados tienen datos en una hoja.
     */
    private function detectarEmpleadosConDatos($sheet, string $tipo): array
    {
        if (!$sheet) return [];

        $empleados = [];

        if ($tipo === 'rapida') {
            // Hoja r+ípida: fila 8+, columna C tiene c+¦digo
            $highestRow = $sheet->getHighestRow();
            for ($row = 8; $row <= $highestRow; $row++) {
                $idEmpleado = (int) $sheet->getCell("B{$row}")->getValue();
                $codigo = trim((string) $sheet->getCell("C{$row}")->getValue());
                if ($idEmpleado && !empty($codigo)) {
                    $empleados[] = $idEmpleado;
                }
            }
        } elseif ($tipo === 'detalle') {
            // Hoja detalle: fila 5+, columnas C en adelante
            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();

            for ($row = 5; $row <= $highestRow; $row++) {
                $idEmpleado = (int) $sheet->getCell("B{$row}")->getValue();
                if (!$idEmpleado) continue;

                // Revisar si tiene alg+¦n dato en las columnas de d+¡as
                $tieneDatos = false;
                for ($colIdx = 2; $colIdx <= 32; $colIdx++) { // C hasta AG (31 d+¡as)
                    $col = $this->numToCol($colIdx);
                    $valor = trim((string) $sheet->getCell("{$col}{$row}")->getValue());
                    if (!empty($valor)) {
                        $tieneDatos = true;
                        break;
                    }
                }
                if ($tieneDatos) {
                    $empleados[] = $idEmpleado;
                }
            }
        }

        return $empleados;
    }

    /**
     * Convierte n+¦mero (0-indexed) a letra de columna Excel.
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
