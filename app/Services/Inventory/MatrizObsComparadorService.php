<?php

namespace App\Services\Inventory;

use App\Models\MatrizObsActivoC;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use RuntimeException;

class MatrizObsComparadorService
{
    public const COLUMNAS_PLANTILLA = [
        'Sucursal / Sede',
        'PLACA',
        'MARCA',
        'TIPO DE EQUIPO',
        'REFERENCIA',
        'SERIAL',
        'UBICACIÓN',
        'TIPO DE UNIDAD',
        'FECHA DE COMPRA',
        'MODALIDAD DE COMPRA',
        'PROVEEDOR',
        'RAM (GB)',
        'MaxRAM (GB)',
        'GENERACIÓN RAM',
        'Valoración RAM',
        'Procesador',
        'Tipo Disco',
        'Disco (GB)',
        'Interfaz Conexión',
    ];

    private const CAMPOS_COMPARABLES = [
        'sucursal_sede' => ['etiqueta' => 'Sucursal / Sede', 'tipo' => 'texto'],
        'placa' => ['etiqueta' => 'PLACA', 'tipo' => 'texto'],
        'marca' => ['etiqueta' => 'MARCA', 'tipo' => 'texto'],
        'tipo' => ['etiqueta' => 'TIPO DE EQUIPO', 'tipo' => 'texto'],
        'referencia' => ['etiqueta' => 'REFERENCIA', 'tipo' => 'texto'],
        'serial' => ['etiqueta' => 'SERIAL', 'tipo' => 'texto'],
        'ubicacion' => ['etiqueta' => 'UBICACIÓN', 'tipo' => 'texto'],
        'tipo_unidad' => ['etiqueta' => 'TIPO DE UNIDAD', 'tipo' => 'texto'],
        'fecha_compra' => ['etiqueta' => 'FECHA DE COMPRA', 'tipo' => 'fecha'],
        'modalidad' => ['etiqueta' => 'MODALIDAD DE COMPRA', 'tipo' => 'texto'],
        'proveedor' => ['etiqueta' => 'PROVEEDOR', 'tipo' => 'texto'],
        'ram' => ['etiqueta' => 'RAM (GB)', 'tipo' => 'numero'],
        'max_ram' => ['etiqueta' => 'MaxRAM (GB)', 'tipo' => 'numero'],
        'generacion_ram' => ['etiqueta' => 'GENERACIÓN RAM', 'tipo' => 'texto'],
        'valoracion_ram' => ['etiqueta' => 'Valoración RAM', 'tipo' => 'numero'],
        'procesador' => ['etiqueta' => 'Procesador', 'tipo' => 'texto'],
        'tipo_disco' => ['etiqueta' => 'Tipo Disco', 'tipo' => 'texto'],
        'disco' => ['etiqueta' => 'Disco (GB)', 'tipo' => 'numero'],
        'interfaz_conexion' => ['etiqueta' => 'Interfaz Conexión', 'tipo' => 'texto'],
    ];

    private const ALIAS_CAMPOS = [
        'sucursal sede' => 'sucursal_sede',
        'sucursal' => 'sucursal_sede',
        'sede' => 'sucursal_sede',
        'placa' => 'placa',
        'marca' => 'marca',
        'tipo de equipo' => 'tipo',
        'tipo equipo' => 'tipo',
        'tipo' => 'tipo',
        'referencia' => 'referencia',
        'serial' => 'serial',
        'ubicacion' => 'ubicacion',
        'tipo de unidad' => 'tipo_unidad',
        'tipo unidad' => 'tipo_unidad',
        'fecha de compra' => 'fecha_compra',
        'fecha compra' => 'fecha_compra',
        'modalidad de compra' => 'modalidad',
        'modalidad' => 'modalidad',
        'proveedor' => 'proveedor',
        'ram gb' => 'ram',
        'ram' => 'ram',
        'tamano ram' => 'ram',
        'maxram gb' => 'max_ram',
        'max ram gb' => 'max_ram',
        'maxram' => 'max_ram',
        'max ram' => 'max_ram',
        'generacion ram' => 'generacion_ram',
        'valoracion ram' => 'valoracion_ram',
        'procesador' => 'procesador',
        'tipo disco' => 'tipo_disco',
        'disco gb' => 'disco',
        'disco' => 'disco',
        'tamano disco' => 'disco',
        'interfaz conexion' => 'interfaz_conexion',
        'interfaz' => 'interfaz_conexion',
    ];

    /**
     * Compara el Excel contra los activos de la consulta (ya filtrada por permisos).
     *
     * @return array{
     *   resumen: array<string, int>,
     *   advertencias: list<string>,
     *   encabezados_detectados: list<string>,
     *   campos_mapeados: array<string, string>,
     *   iguales: list<array<string, mixed>>,
     *   diferencias: list<array<string, mixed>>,
     *   solo_excel: list<array<string, mixed>>,
     *   solo_bd: list<array<string, mixed>>,
     *   sin_clave: list<array<string, mixed>>
     * }
     */
    public function comparar(string $rutaArchivo, Builder $queryActivos): array
    {
        $filasExcel = $this->leerExcel($rutaArchivo);
        $activosBd = $this->cargarActivos($queryActivos);

        $indicePlaca = [];
        $indiceSerial = [];
        foreach ($activosBd as $id => $activo) {
            if ($activo['placa_norm'] !== '') {
                $indicePlaca[$activo['placa_norm']][] = $id;
            }
            if ($activo['serial_norm'] !== '') {
                $indiceSerial[$activo['serial_norm']][] = $id;
            }
        }

        $usadosBd = [];
        $iguales = [];
        $diferencias = [];
        $soloExcel = [];
        $sinClave = [];
        $advertencias = $filasExcel['advertencias'];
        $placasExcel = [];
        $serialesExcel = [];

        foreach ($filasExcel['filas'] as $fila) {
            $placaNorm = $this->normalizarTexto($fila['placa'] ?? '');
            $serialNorm = $this->normalizarTexto($fila['serial'] ?? '');

            if ($placaNorm === '' && $serialNorm === '') {
                $sinClave[] = $this->compactarExcel($fila);
                continue;
            }

            if ($placaNorm !== '') {
                $placasExcel[$placaNorm] = ($placasExcel[$placaNorm] ?? 0) + 1;
            }
            if ($serialNorm !== '') {
                $serialesExcel[$serialNorm] = ($serialesExcel[$serialNorm] ?? 0) + 1;
            }

            $match = $this->buscarMatch($fila, $placaNorm, $serialNorm, $indicePlaca, $indiceSerial, $activosBd, $usadosBd);

            if ($match === null) {
                $soloExcel[] = $this->compactarExcel($fila);
                continue;
            }

            $usadosBd[$match['id']] = true;
            $activo = $activosBd[$match['id']];
            $campos = $this->diffCampos($fila, $activo);

            $base = [
                'fila_excel' => $fila['fila_excel'],
                'id_activo' => $activo['id'],
                'id_activo_glpi' => $activo['id_activo_glpi'],
                'nombre_equipo' => $activo['nombre_equipo'],
                'placa' => $activo['placa'] !== '' ? $activo['placa'] : ($fila['placa'] ?? ''),
                'serial' => $activo['serial'] !== '' ? $activo['serial'] : ($fila['serial'] ?? ''),
                'coincidencia_por' => $match['por'],
            ];

            if (empty($campos)) {
                $iguales[] = $base;
            } else {
                $base['total_diferencias'] = count($campos);
                $base['campos'] = $campos;
                $diferencias[] = $base;
            }
        }

        foreach ($placasExcel as $placa => $count) {
            if ($count > 1) {
                $advertencias[] = "La placa \"{$placa}\" aparece {$count} veces en el Excel.";
            }
        }
        foreach ($serialesExcel as $serial => $count) {
            if ($count > 1) {
                $advertencias[] = "El serial \"{$serial}\" aparece {$count} veces en el Excel.";
            }
        }

        $soloBd = [];
        foreach ($activosBd as $id => $activo) {
            if (!isset($usadosBd[$id])) {
                $soloBd[] = [
                    'id_activo' => $activo['id'],
                    'id_activo_glpi' => $activo['id_activo_glpi'],
                    'nombre_equipo' => $activo['nombre_equipo'],
                    'placa' => $activo['placa'],
                    'serial' => $activo['serial'],
                    'sucursal_sede' => $activo['sucursal_sede'],
                    'marca' => $activo['marca'],
                    'tipo' => $activo['tipo'],
                    'ubicacion' => $activo['ubicacion'],
                ];
            }
        }

        return [
            'resumen' => [
                'filas_excel' => $filasExcel['total_filas'],
                'filas_validas' => count($filasExcel['filas']),
                'sin_clave' => count($sinClave),
                'activos_bd' => count($activosBd),
                'iguales' => count($iguales),
                'diferencias' => count($diferencias),
                'solo_excel' => count($soloExcel),
                'solo_bd' => count($soloBd),
            ],
            'advertencias' => array_values(array_unique($advertencias)),
            'encabezados_detectados' => $filasExcel['encabezados_detectados'],
            'campos_mapeados' => $filasExcel['campos_mapeados'],
            'iguales' => $iguales,
            'diferencias' => $diferencias,
            'solo_excel' => $soloExcel,
            'solo_bd' => $soloBd,
            'sin_clave' => $sinClave,
        ];
    }

    public function generarPlantilla(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Activos');

        foreach (self::COLUMNAS_PLANTILLA as $index => $titulo) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($col . '1', $titulo);
            $sheet->getColumnDimension($col)->setWidth(max(16, mb_strlen($titulo) + 4));
        }

        $lastCol = Coordinate::stringFromColumnIndex(count(self::COLUMNAS_PLANTILLA));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A5F'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    /**
     * @return array{
     *   filas: list<array<string, mixed>>,
     *   total_filas: int,
     *   encabezados_detectados: list<string>,
     *   campos_mapeados: array<string, string>,
     *   advertencias: list<string>
     * }
     */
    private function leerExcel(string $rutaArchivo): array
    {
        $spreadsheet = IOFactory::load($rutaArchivo);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);

        if ($highestRow < 2) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('El archivo no tiene filas de datos para comparar.');
        }

        $mapaColumnas = [];
        $encabezados = [];
        $camposMapeados = [];
        $advertencias = [];

        for ($col = 1; $col <= $highestColIndex; $col++) {
            $raw = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($col) . '1')->getValue());
            if ($raw === '') {
                continue;
            }
            $encabezados[] = $raw;
            $campo = $this->mapearCampo($this->normalizarEncabezado($raw));
            if ($campo === null) {
                $advertencias[] = "Columna no reconocida y se omitió: \"{$raw}\".";
                continue;
            }
            $mapaColumnas[$col] = $campo;
            $camposMapeados[$raw] = self::CAMPOS_COMPARABLES[$campo]['etiqueta'];
        }

        if (!in_array('placa', $mapaColumnas, true) && !in_array('serial', $mapaColumnas, true)) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('El archivo debe incluir al menos la columna PLACA o SERIAL para cruzar con la base de datos.');
        }

        $filas = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $fila = ['fila_excel' => $row];
            $vacia = true;

            foreach ($mapaColumnas as $col => $campo) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                $valor = $this->valorCelda($cell, self::CAMPOS_COMPARABLES[$campo]['tipo']);
                $fila[$campo] = $valor;
                if ($valor !== '') {
                    $vacia = false;
                }
            }

            if ($vacia) {
                continue;
            }

            $filas[] = $fila;
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'filas' => $filas,
            'total_filas' => max(0, $highestRow - 1),
            'encabezados_detectados' => $encabezados,
            'campos_mapeados' => $camposMapeados,
            'advertencias' => $advertencias,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cargarActivos(Builder $query): array
    {
        $query->select([
            self::tablaC() . '.id',
            self::tablaC() . '.id_activo_glpi',
            self::tablaC() . '.placa',
            self::tablaC() . '.serial',
            self::tablaC() . '.ubicacion',
            self::tablaC() . '.nombre_equipo',
            self::tablaC() . '.id_sucursal',
            self::tablaC() . '.id_sede',
        ])->with([
            'detalle:id,activo_c_id,marca,tipo,referencia,tipo_unidad,fecha_compra,modalidad,proveedor,tamano_ram,max_ram,generacion_ram,valoracion_ram,procesador,tipo_disco,tamano_disco,interfaz_conexion',
            'sucursal:id,nombre',
            'sede:id,nombre',
        ]);

        $resultado = [];

        $query->chunkById(500, function ($activos) use (&$resultado) {
            foreach ($activos as $activo) {
                /** @var MatrizObsActivoC $activo */
                $detalle = $activo->detalle;
                $sucursal = trim((string) ($activo->sucursal?->nombre ?? ''));
                $sede = trim((string) ($activo->sede?->nombre ?? ''));
                $sucursalSede = trim(implode(' / ', array_filter([$sucursal, $sede], fn ($v) => $v !== '')), ' /');

                $placa = trim((string) ($activo->placa ?? ''));
                $serial = trim((string) ($activo->serial ?? ''));

                $resultado[$activo->id] = [
                    'id' => $activo->id,
                    'id_activo_glpi' => $activo->id_activo_glpi,
                    'nombre_equipo' => (string) ($activo->nombre_equipo ?? ''),
                    'placa' => $placa,
                    'serial' => $serial,
                    'placa_norm' => $this->normalizarTexto($placa),
                    'serial_norm' => $this->normalizarTexto($serial),
                    'sucursal_sede' => $sucursalSede,
                    'sucursal' => $sucursal,
                    'sede' => $sede,
                    'ubicacion' => trim((string) ($activo->ubicacion ?? '')),
                    'marca' => trim((string) ($detalle?->marca ?? '')),
                    'tipo' => trim((string) ($detalle?->tipo ?? '')),
                    'referencia' => trim((string) ($detalle?->referencia ?? '')),
                    'tipo_unidad' => trim((string) ($detalle?->tipo_unidad ?? '')),
                    'fecha_compra' => $detalle?->fecha_compra?->format('Y-m-d') ?? '',
                    'modalidad' => trim((string) ($detalle?->modalidad ?? '')),
                    'proveedor' => trim((string) ($detalle?->proveedor ?? '')),
                    'ram' => $this->formatearNumero($detalle?->tamano_ram),
                    'max_ram' => $this->formatearNumero($detalle?->max_ram),
                    'generacion_ram' => trim((string) ($detalle?->generacion_ram ?? '')),
                    'valoracion_ram' => $this->formatearNumero($detalle?->valoracion_ram),
                    'procesador' => trim((string) ($detalle?->procesador ?? '')),
                    'tipo_disco' => trim((string) ($detalle?->tipo_disco ?? '')),
                    'disco' => $this->formatearNumero($detalle?->tamano_disco),
                    'interfaz_conexion' => trim((string) ($detalle?->interfaz_conexion ?? '')),
                ];
            }
        }, self::tablaC() . '.id', 'id');

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, list<int>>  $indicePlaca
     * @param  array<string, list<int>>  $indiceSerial
     * @param  array<int, array<string, mixed>>  $activosBd
     * @param  array<int, true>  $usadosBd
     * @return array{id: int, por: string}|null
     */
    private function buscarMatch(
        array $fila,
        string $placaNorm,
        string $serialNorm,
        array $indicePlaca,
        array $indiceSerial,
        array $activosBd,
        array $usadosBd
    ): ?array {
        $candidatos = [];

        if ($placaNorm !== '' && isset($indicePlaca[$placaNorm])) {
            foreach ($indicePlaca[$placaNorm] as $id) {
                if (!isset($usadosBd[$id])) {
                    $candidatos[$id] = 'placa';
                }
            }
        }

        if (empty($candidatos) && $serialNorm !== '' && isset($indiceSerial[$serialNorm])) {
            foreach ($indiceSerial[$serialNorm] as $id) {
                if (!isset($usadosBd[$id])) {
                    $candidatos[$id] = 'serial';
                }
            }
        }

        if (empty($candidatos)) {
            return null;
        }

        if (count($candidatos) === 1) {
            $id = array_key_first($candidatos);
            return ['id' => $id, 'por' => $candidatos[$id]];
        }

        if ($serialNorm !== '') {
            foreach ($candidatos as $id => $por) {
                if (($activosBd[$id]['serial_norm'] ?? '') === $serialNorm) {
                    return ['id' => $id, 'por' => $por === 'placa' ? 'placa+serial' : 'serial'];
                }
            }
        }

        $id = array_key_first($candidatos);
        return ['id' => $id, 'por' => $candidatos[$id]];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $activo
     * @return list<array{campo: string, etiqueta: string, excel: string, bd: string}>
     */
    private function diffCampos(array $fila, array $activo): array
    {
        $diffs = [];

        foreach (self::CAMPOS_COMPARABLES as $campo => $meta) {
            if (!array_key_exists($campo, $fila)) {
                continue;
            }

            $excel = (string) ($fila[$campo] ?? '');
            $bd = (string) ($activo[$campo] ?? '');

            if ($this->valoresIguales($excel, $bd, $meta['tipo'], $campo, $activo)) {
                continue;
            }

            $diffs[] = [
                'campo' => $campo,
                'etiqueta' => $meta['etiqueta'],
                'excel' => $this->presentarValor($excel, $meta['tipo']),
                'bd' => $this->presentarValor($bd, $meta['tipo']),
            ];
        }

        return $diffs;
    }

    /**
     * @param  array<string, mixed>  $activo
     */
    private function valoresIguales(string $excel, string $bd, string $tipo, string $campo, array $activo): bool
    {
        if ($this->estaVacio($excel) && $this->estaVacio($bd)) {
            return true;
        }

        if ($campo === 'sucursal_sede') {
            return $this->sucursalSedeCoincide($excel, $activo);
        }

        if ($tipo === 'numero') {
            $nExcel = $this->extraerNumero($excel);
            $nBd = $this->extraerNumero($bd);
            if ($nExcel === null && $nBd === null) {
                return true;
            }
            if ($nExcel === null || $nBd === null) {
                return false;
            }
            return abs($nExcel - $nBd) < 0.01;
        }

        if ($tipo === 'fecha') {
            $fExcel = $this->normalizarFecha($excel);
            $fBd = $this->normalizarFecha($bd);
            return $fExcel !== null && $fBd !== null && $fExcel === $fBd;
        }

        return $this->normalizarTexto($excel) === $this->normalizarTexto($bd);
    }

    /**
     * @param  array<string, mixed>  $activo
     */
    private function sucursalSedeCoincide(string $excel, array $activo): bool
    {
        $excelNorm = $this->normalizarTexto($excel);
        if ($excelNorm === '') {
            return $this->normalizarTexto($activo['sucursal_sede'] ?? '') === '';
        }

        $compuesto = $this->normalizarTexto($activo['sucursal_sede'] ?? '');
        if ($excelNorm === $compuesto) {
            return true;
        }

        $sucursal = $this->normalizarTexto($activo['sucursal'] ?? '');
        $sede = $this->normalizarTexto($activo['sede'] ?? '');

        if ($sucursal !== '' && $excelNorm === $sucursal) {
            return true;
        }
        if ($sede !== '' && $excelNorm === $sede) {
            return true;
        }
        if ($sucursal !== '' && $sede !== '' && str_contains($excelNorm, $sucursal) && str_contains($excelNorm, $sede)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function compactarExcel(array $fila): array
    {
        $compacto = [
            'fila_excel' => $fila['fila_excel'],
            'placa' => $fila['placa'] ?? '',
            'serial' => $fila['serial'] ?? '',
            'sucursal_sede' => $fila['sucursal_sede'] ?? '',
            'marca' => $fila['marca'] ?? '',
            'tipo' => $fila['tipo'] ?? '',
            'referencia' => $fila['referencia'] ?? '',
            'ubicacion' => $fila['ubicacion'] ?? '',
        ];

        foreach (self::CAMPOS_COMPARABLES as $campo => $meta) {
            if (!array_key_exists($campo, $compacto)) {
                $compacto[$campo] = $fila[$campo] ?? '';
            }
        }

        return $compacto;
    }

    private function valorCelda($cell, string $tipo): string
    {
        $raw = $cell->getCalculatedValue();

        if ($raw === null || $raw === '') {
            return '';
        }

        if ($tipo === 'fecha' || ExcelDate::isDateTime($cell)) {
            $fecha = $this->normalizarFecha($raw);
            return $fecha ?? trim((string) $raw);
        }

        if ($tipo === 'numero') {
            $numero = $this->formatearNumero($raw);
            return $numero;
        }

        return trim((string) $raw);
    }

    private function mapearCampo(string $encabezadoNormalizado): ?string
    {
        return self::ALIAS_CAMPOS[$encabezadoNormalizado] ?? null;
    }

    private function normalizarEncabezado(string $header): string
    {
        $h = $this->quitarAcentos(mb_strtolower(trim($header), 'UTF-8'));
        $h = preg_replace('/[^a-z0-9]+/', ' ', $h) ?? $h;
        return trim(preg_replace('/\s+/', ' ', $h) ?? $h);
    }

    private function normalizarTexto(string $valor): string
    {
        $v = $this->quitarAcentos(mb_strtolower(trim($valor), 'UTF-8'));
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        return trim($v);
    }

    private function quitarAcentos(string $valor): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];
        return strtr($valor, $map);
    }

    private function normalizarFecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        if (is_numeric($valor)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $texto = trim((string) $valor);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd/m/y', 'm/d/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $texto);
            if ($dt instanceof \DateTime) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($texto);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    private function extraerNumero(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_numeric($valor)) {
            return (float) $valor;
        }

        $texto = str_replace(['%', 'GB', 'gb', 'Gb', ' '], '', (string) $valor);
        $texto = str_replace(',', '.', $texto);
        $texto = preg_replace('/[^0-9.\-]/', '', $texto) ?? $texto;

        if ($texto === '' || !is_numeric($texto)) {
            return null;
        }

        return (float) $texto;
    }

    private function formatearNumero(mixed $valor): string
    {
        $n = $this->extraerNumero($valor);
        if ($n === null) {
            return is_string($valor) ? trim($valor) : '';
        }
        $formatted = number_format($n, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    private function presentarValor(string $valor, string $tipo): string
    {
        if ($this->estaVacio($valor)) {
            return '';
        }
        if ($tipo === 'fecha') {
            $fecha = $this->normalizarFecha($valor);
            if ($fecha) {
                $dt = \DateTime::createFromFormat('Y-m-d', $fecha);
                return $dt ? $dt->format('d/m/Y') : $valor;
            }
        }
        return $valor;
    }

    private function estaVacio(string $valor): bool
    {
        return trim($valor) === '' || strtolower(trim($valor)) === 'null';
    }

    private static function tablaC(): string
    {
        return 'matzobs_activos_c';
    }
}
