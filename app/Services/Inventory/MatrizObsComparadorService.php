<?php

namespace App\Services\Inventory;

use App\Models\MatrizObsActivoC;
use App\Models\MatrizObsActivoD;
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
        'Recurso/id',
        'PLACA',
        'MARCA',
        'TIPO DE EQUIPO',
        'REFERENCIA',
        'SERIAL',
        'UBICACIÓN',
        'TIPO DE UNIDAD',
        'FECHA DE COMPRA',
        'MODALIDAD DE COMPRA',
        'PROCESADOR',
        'SODIMM',
        'Edad y Valoración',
        'RAM',
        'MemRam',
        'GENERACION',
        'Valoración Procesador',
        'Valoración Memoria',
        'Tipo Disco',
        'Disco',
        'Valoración Disco',
        'Puntaje',
        'Concepto',
    ];

    private const CAMPOS_COMPARABLES = [
        'recurso_id' => ['etiqueta' => 'Recurso/id', 'tipo' => 'texto'],
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
        'procesador' => ['etiqueta' => 'PROCESADOR', 'tipo' => 'texto'],
        'generacion_ram' => ['etiqueta' => 'GENERACION / SODIMM', 'tipo' => 'texto'],
        'edad' => ['etiqueta' => 'Edad', 'tipo' => 'numero'],
        'valoracion_edad' => ['etiqueta' => 'Edad y Valoración', 'tipo' => 'numero'],
        'ram' => ['etiqueta' => 'RAM', 'tipo' => 'numero'],
        'max_ram' => ['etiqueta' => 'MemRam', 'tipo' => 'numero'],
        'valoracion_procesador' => ['etiqueta' => 'Valoración Procesador', 'tipo' => 'numero'],
        'valoracion_ram' => ['etiqueta' => 'Valoración Memoria', 'tipo' => 'numero'],
        'tipo_disco' => ['etiqueta' => 'Tipo Disco', 'tipo' => 'texto'],
        'disco' => ['etiqueta' => 'Disco', 'tipo' => 'numero'],
        'valoracion_disco' => ['etiqueta' => 'Valoración Disco', 'tipo' => 'numero'],
        'interfaz_conexion' => ['etiqueta' => 'Interfaz Conexión', 'tipo' => 'texto'],
        'puntaje' => ['etiqueta' => 'Puntaje', 'tipo' => 'numero'],
        'concepto' => ['etiqueta' => 'Concepto', 'tipo' => 'concepto'],
    ];

    private const CAMPOS_NO_COMPARAR = [
        'ubicacion',
        'tipo_unidad',
        'procesador',
        'max_ram',
        'tipo_disco',
        'puntaje',
    ];

    private const ALIAS_CAMPOS = [
        'recurso id' => 'recurso_id',
        'recurso' => 'recurso_id',
        'id recurso' => 'recurso_id',
        'id' => 'recurso_id',
        'id glpi' => 'recurso_id',
        'id activo glpi' => 'recurso_id',
        'nombre equipo' => 'recurso_id',
        'nombre del equipo' => 'recurso_id',
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
        'procesador' => 'procesador',
        'sodimm' => 'generacion_ram',
        'generacion' => 'generacion_ram',
        'generacion ram' => 'generacion_ram',
        'edad y valoracion' => 'valoracion_edad',
        'edad valoracion' => 'valoracion_edad',
        'valoracion edad' => 'valoracion_edad',
        'edad' => 'edad',
        'ram gb' => 'ram',
        'ram' => 'ram',
        'tamano ram' => 'ram',
        'memram' => 'max_ram',
        'mem ram' => 'max_ram',
        'maxram gb' => 'max_ram',
        'max ram gb' => 'max_ram',
        'maxram' => 'max_ram',
        'max ram' => 'max_ram',
        'valoracion procesador' => 'valoracion_procesador',
        'valoracion memoria' => 'valoracion_ram',
        'valoracion memoria ram' => 'valoracion_ram',
        'valoracion ram' => 'valoracion_ram',
        'tipo disco' => 'tipo_disco',
        'disco gb' => 'disco',
        'disco' => 'disco',
        'tamano disco' => 'disco',
        'valoracion disco' => 'valoracion_disco',
        'interfaz conexion' => 'interfaz_conexion',
        'interfaz' => 'interfaz_conexion',
        'puntaje' => 'puntaje',
        'concepto' => 'concepto',
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
        $indiceRecurso = [];
        foreach ($activosBd as $id => $activo) {
            if ($activo['placa_norm'] !== '') {
                $indicePlaca[$activo['placa_norm']][] = $id;
            }
            if ($activo['serial_norm'] !== '') {
                $indiceSerial[$activo['serial_norm']][] = $id;
            }
            foreach ($activo['recurso_claves'] as $clave) {
                if ($clave !== '') {
                    $indiceRecurso[$clave][] = $id;
                }
            }
        }

        $usadosBd = [];
        $usadasExcel = [];
        $pares = [];
        $iguales = [];
        $diferencias = [];
        $soloExcel = [];
        $sinClave = [];
        $advertencias = $filasExcel['advertencias'];
        $placasExcel = [];
        $serialesExcel = [];
        $cruces = [
            'placa+serial' => 0,
            'placa' => 0,
            'serial' => 0,
            'recurso' => 0,
        ];

        foreach ($filasExcel['filas'] as $fila) {
            $placaNorm = $this->normalizarTexto($fila['placa'] ?? '');
            $serialNorm = $this->normalizarTexto($fila['serial'] ?? '');
            if ($placaNorm !== '') {
                $placasExcel[$placaNorm] = ($placasExcel[$placaNorm] ?? 0) + 1;
            }
            if ($serialNorm !== '') {
                $serialesExcel[$serialNorm] = ($serialesExcel[$serialNorm] ?? 0) + 1;
            }
        }

        // 1) Primero: coinciden placa y serial a la vez
        foreach ($filasExcel['filas'] as $idx => $fila) {
            $placaNorm = $this->normalizarTexto($fila['placa'] ?? '');
            $serialNorm = $this->normalizarTexto($fila['serial'] ?? '');
            if ($placaNorm === '' || $serialNorm === '') {
                continue;
            }

            $id = $this->buscarPorPlacaYSerial($placaNorm, $serialNorm, $indicePlaca, $activosBd, $usadosBd);
            if ($id === null) {
                continue;
            }

            $usadosBd[$id] = true;
            $usadasExcel[$idx] = true;
            $pares[] = ['fila' => $fila, 'id' => $id, 'por' => 'placa+serial'];
        }

        // 2) Luego: coinciden solo por placa o solo por serial
        foreach ($filasExcel['filas'] as $idx => $fila) {
            if (isset($usadasExcel[$idx])) {
                continue;
            }

            $placaNorm = $this->normalizarTexto($fila['placa'] ?? '');
            $serialNorm = $this->normalizarTexto($fila['serial'] ?? '');
            $match = $this->buscarPorPlacaOSerial($placaNorm, $serialNorm, $indicePlaca, $indiceSerial, $usadosBd);
            if ($match === null) {
                continue;
            }

            $usadosBd[$match['id']] = true;
            $usadasExcel[$idx] = true;
            $pares[] = ['fila' => $fila, 'id' => $match['id'], 'por' => $match['por']];
        }

        // 3) Último recurso: Recurso/id (si no hubo placa ni serial)
        foreach ($filasExcel['filas'] as $idx => $fila) {
            if (isset($usadasExcel[$idx])) {
                continue;
            }

            $recursoNorm = $this->normalizarTexto($fila['recurso_id'] ?? '');
            $match = $this->buscarPorRecurso($recursoNorm, $indiceRecurso, $usadosBd);
            if ($match === null) {
                continue;
            }

            $usadosBd[$match['id']] = true;
            $usadasExcel[$idx] = true;
            $pares[] = ['fila' => $fila, 'id' => $match['id'], 'por' => $match['por']];
        }

        foreach ($pares as $par) {
            $fila = $par['fila'];
            $activo = $activosBd[$par['id']];
            $campos = $this->compararCampos($fila, $activo);
            $cruces[$par['por']] = ($cruces[$par['por']] ?? 0) + 1;
            $diffs = array_values(array_filter($campos, static fn (array $c) => !$c['igual']));
            $totalDiffs = count($diffs);

            $base = [
                'fila_excel' => $fila['fila_excel'],
                'id_activo' => $activo['id'],
                'id_activo_glpi' => $activo['id_activo_glpi'],
                'nombre_equipo' => $activo['nombre_equipo'],
                'agente' => $activo['agente'] ?? '',
                'sucursal_sede' => $activo['sucursal_sede'] ?? '',
                'recurso_id' => $fila['recurso_id'] ?? (string) ($activo['id_activo_glpi'] ?? ''),
                'placa' => $activo['placa'] !== '' ? $activo['placa'] : ($fila['placa'] ?? ''),
                'serial' => $activo['serial'] !== '' ? $activo['serial'] : ($fila['serial'] ?? ''),
                'marca' => $activo['marca'] ?? '',
                'tipo' => $activo['tipo'] ?? '',
                'referencia' => $activo['referencia'] ?? '',
                'ubicacion' => $activo['ubicacion'] ?? '',
                'tipo_unidad' => $activo['tipo_unidad'] ?? '',
                'procesador' => $activo['procesador'] ?? '',
                'ram' => $activo['ram'] ?? '',
                'max_ram' => $activo['max_ram'] ?? '',
                'generacion_ram' => $activo['generacion_ram'] ?? '',
                'tipo_disco' => $activo['tipo_disco'] ?? '',
                'disco' => $activo['disco'] ?? '',
                'edad' => $activo['edad'] ?? '',
                'valoracion_edad' => $activo['valoracion_edad'] ?? '',
                'valoracion_ram' => $activo['valoracion_ram'] ?? '',
                'valoracion_procesador' => $activo['valoracion_procesador'] ?? '',
                'valoracion_disco' => $activo['valoracion_disco'] ?? '',
                'fecha_compra' => $activo['fecha_compra'] ?? '',
                'modalidad' => $activo['modalidad'] ?? '',
                'puntaje' => $activo['puntaje'] ?? '',
                'concepto' => $activo['concepto'] ?? '',
                'puntaje_excel' => $fila['puntaje'] ?? '',
                'puntaje_bd' => $activo['puntaje'],
                'concepto_excel' => $fila['concepto'] ?? '',
                'concepto_bd' => $activo['concepto'],
                'fecha_compra_excel' => $fila['fecha_compra'] ?? '',
                'fecha_compra_bd' => $activo['fecha_compra'] ?? '',
                'modalidad_excel' => $fila['modalidad'] ?? '',
                'modalidad_bd' => $activo['modalidad'] ?? '',
                'max_ram_excel' => $fila['max_ram'] ?? '',
                'max_ram_bd' => $activo['max_ram'] ?? '',
                'coincidencia_por' => $par['por'],
                'total_diferencias' => $totalDiffs,
                'total_iguales' => count($campos) - $totalDiffs,
                'campos' => $diffs,
            ];

            if ($totalDiffs === 0) {
                $base['campos'] = [];
                $iguales[] = $base;
            } else {
                $diferencias[] = $base;
            }
        }

        foreach ($filasExcel['filas'] as $idx => $fila) {
            if (isset($usadasExcel[$idx])) {
                continue;
            }

            $placaNorm = $this->normalizarTexto($fila['placa'] ?? '');
            $serialNorm = $this->normalizarTexto($fila['serial'] ?? '');
            $recursoNorm = $this->normalizarTexto($fila['recurso_id'] ?? '');

            if ($placaNorm === '' && $serialNorm === '' && $recursoNorm === '') {
                $sinClave[] = $this->compactarExcel($fila);
                continue;
            }

            $soloExcel[] = $this->compactarExcel($fila);
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
                    'id_activo' => $activo['id'] ?? null,
                    'id_activo_glpi' => $activo['id_activo_glpi'] ?? null,
                    'nombre_equipo' => $activo['nombre_equipo'] ?? '',
                    'recurso_id' => (string) ($activo['id_activo_glpi'] ?? ''),
                    'placa' => $activo['placa'] ?? '',
                    'serial' => $activo['serial'] ?? '',
                    'sucursal_sede' => $activo['sucursal_sede'] ?? '',
                    'marca' => $activo['marca'] ?? '',
                    'tipo' => $activo['tipo'] ?? '',
                    'ubicacion' => $activo['ubicacion'] ?? '',
                    'puntaje' => $activo['puntaje'] ?? '',
                    'concepto' => $activo['concepto'] ?? '',
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
                'cruce_placa_serial' => $cruces['placa+serial'],
                'cruce_placa' => $cruces['placa'],
                'cruce_serial' => $cruces['serial'],
                'cruce_recurso' => $cruces['recurso'],
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
            $meta = $this->metaCampo($campo);
            if ($campo === null || $meta === null) {
                $advertencias[] = "Columna no reconocida y se omitió: \"{$raw}\".";
                continue;
            }
            $mapaColumnas[$col] = $campo;
            $camposMapeados[$raw] = $meta['etiqueta'];
        }

        if (
            !in_array('placa', $mapaColumnas, true)
            && !in_array('serial', $mapaColumnas, true)
            && !in_array('recurso_id', $mapaColumnas, true)
        ) {
            $spreadsheet->disconnectWorksheets();
            throw new RuntimeException('El archivo debe incluir al menos la columna Recurso/id, PLACA o SERIAL para cruzar con la matriz.');
        }

        $filas = [];
        $clavesVacias = array_fill_keys(array_keys(self::CAMPOS_COMPARABLES), '');
        for ($row = 2; $row <= $highestRow; $row++) {
            $fila = $clavesVacias;
            $fila['fila_excel'] = $row;
            $vacia = true;

            foreach ($mapaColumnas as $col => $campo) {
                $meta = $this->metaCampo($campo);
                if ($meta === null) {
                    continue;
                }
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . $row);
                $valor = $this->valorCelda($cell, $meta['tipo']);
                $fila[$campo] = $valor;
                if ($valor !== '') {
                    $vacia = false;
                }
            }

            if ($vacia) {
                continue;
            }

            $this->separarEdadYValoracion($fila);
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
            self::tablaC() . '.agente',
            self::tablaC() . '.id_sucursal',
            self::tablaC() . '.id_sede',
            self::tablaC() . '.puntaje',
        ])->with([
            'detalle:id,activo_c_id,marca,tipo,referencia,tipo_unidad,fecha_compra,modalidad,proveedor,edad,valoracion_edad,tamano_ram,max_ram,generacion_ram,valoracion_ram,procesador,valoracion_procesador,tipo_disco,tamano_disco,interfaz_conexion,valoracion_disco',
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
                $nombreEquipo = trim((string) ($activo->nombre_equipo ?? ''));
                $idGlpi = $activo->id_activo_glpi;
                $puntaje = $this->formatearNumero($activo->puntaje);

                $resultado[$activo->id] = [
                    'id' => $activo->id,
                    'id_activo_glpi' => $idGlpi,
                    'nombre_equipo' => $nombreEquipo,
                    'agente' => trim((string) ($activo->agente ?? '')),
                    'recurso_id' => $idGlpi !== null && $idGlpi !== '' ? (string) $idGlpi : $nombreEquipo,
                    'recurso_claves' => $this->clavesRecurso($idGlpi, $nombreEquipo),
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
                    'edad' => $this->formatearNumero($detalle?->edad),
                    'valoracion_edad' => $this->formatearNumero($detalle?->valoracion_edad),
                    'ram' => $this->formatearNumero($detalle?->tamano_ram),
                    'max_ram' => $this->formatearNumero($detalle?->max_ram),
                    'generacion_ram' => trim((string) ($detalle?->generacion_ram ?? '')),
                    'valoracion_ram' => $this->formatearNumero($detalle?->valoracion_ram),
                    'procesador' => trim((string) ($detalle?->procesador ?? '')),
                    'valoracion_procesador' => $this->formatearNumero($detalle?->valoracion_procesador),
                    'tipo_disco' => trim((string) ($detalle?->tipo_disco ?? '')),
                    'disco' => $this->formatearNumero($detalle?->tamano_disco),
                    'interfaz_conexion' => trim((string) ($detalle?->interfaz_conexion ?? '')),
                    'valoracion_disco' => $this->formatearNumero($detalle?->valoracion_disco),
                    'puntaje' => $puntaje,
                    'concepto' => $this->conceptoDesdePuntaje($activo->puntaje),
                ];
            }
        }, self::tablaC() . '.id', 'id');

        return $resultado;
    }

    /**
     * @param  array<string, list<int>>  $indicePlaca
     * @param  array<int, array<string, mixed>>  $activosBd
     * @param  array<int, true>  $usadosBd
     */
    private function buscarPorPlacaYSerial(
        string $placaNorm,
        string $serialNorm,
        array $indicePlaca,
        array $activosBd,
        array $usadosBd
    ): ?int {
        if ($placaNorm === '' || $serialNorm === '' || !isset($indicePlaca[$placaNorm])) {
            return null;
        }

        foreach ($indicePlaca[$placaNorm] as $id) {
            if (isset($usadosBd[$id])) {
                continue;
            }
            if (($activosBd[$id]['serial_norm'] ?? '') === $serialNorm) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<int>>  $indicePlaca
     * @param  array<string, list<int>>  $indiceSerial
     * @param  array<int, true>  $usadosBd
     * @return array{id: int, por: string}|null
     */
    private function buscarPorPlacaOSerial(
        string $placaNorm,
        string $serialNorm,
        array $indicePlaca,
        array $indiceSerial,
        array $usadosBd
    ): ?array {
        if ($placaNorm !== '' && isset($indicePlaca[$placaNorm])) {
            foreach ($indicePlaca[$placaNorm] as $id) {
                if (!isset($usadosBd[$id])) {
                    return ['id' => $id, 'por' => 'placa'];
                }
            }
        }

        if ($serialNorm !== '' && isset($indiceSerial[$serialNorm])) {
            foreach ($indiceSerial[$serialNorm] as $id) {
                if (!isset($usadosBd[$id])) {
                    return ['id' => $id, 'por' => 'serial'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<int>>  $indiceRecurso
     * @param  array<int, true>  $usadosBd
     * @return array{id: int, por: string}|null
     */
    private function buscarPorRecurso(string $recursoNorm, array $indiceRecurso, array $usadosBd): ?array
    {
        if ($recursoNorm === '') {
            return null;
        }

        foreach ($this->variantesRecurso($recursoNorm) as $clave) {
            if (!isset($indiceRecurso[$clave])) {
                continue;
            }
            foreach ($indiceRecurso[$clave] as $id) {
                if (!isset($usadosBd[$id])) {
                    return ['id' => $id, 'por' => 'recurso'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $activo
     * @return list<array{campo: string, etiqueta: string, excel: string, bd: string, igual: bool}>
     */
    private function compararCampos(array $fila, array $activo): array
    {
        $campos = [];

        foreach (self::CAMPOS_COMPARABLES as $campo => $meta) {
            if (in_array($campo, self::CAMPOS_NO_COMPARAR, true)) {
                continue;
            }
            if (!array_key_exists($campo, $fila)) {
                continue;
            }

            $excel = (string) ($fila[$campo] ?? '');
            $bd = (string) ($activo[$campo] ?? '');
            $igual = $this->valoresIguales($excel, $bd, $meta['tipo'], $campo, $activo);

            $campos[] = [
                'campo' => $campo,
                'etiqueta' => $meta['etiqueta'],
                'excel' => $this->presentarValor($excel, $meta['tipo']),
                'bd' => $this->presentarValor($bd, $meta['tipo']),
                'igual' => $igual,
            ];
        }

        return $campos;
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

        if ($tipo === 'concepto' || $campo === 'concepto') {
            return $this->normalizarConcepto($excel) === $this->normalizarConcepto($bd);
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
            'recurso_id' => $fila['recurso_id'] ?? '',
            'placa' => $fila['placa'] ?? '',
            'serial' => $fila['serial'] ?? '',
            'sucursal_sede' => $fila['sucursal_sede'] ?? '',
            'marca' => $fila['marca'] ?? '',
            'tipo' => $fila['tipo'] ?? '',
            'referencia' => $fila['referencia'] ?? '',
            'ubicacion' => $fila['ubicacion'] ?? '',
            'puntaje' => $fila['puntaje'] ?? '',
            'concepto' => $fila['concepto'] ?? '',
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
        try {
            $raw = $cell->getCalculatedValue();
        } catch (\Throwable) {
            $raw = $cell->getValue();
        }

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

    /**
     * @return array{etiqueta: string, tipo: string}|null
     */
    private function metaCampo(?string $campo): ?array
    {
        if ($campo === null || !isset(self::CAMPOS_COMPARABLES[$campo])) {
            return null;
        }

        return self::CAMPOS_COMPARABLES[$campo];
    }

    /**
     * Si "Edad y Valoración" viene como "7 / 50" o "7-50", separa edad y valoración.
     *
     * @param  array<string, mixed>  $fila
     */
    private function separarEdadYValoracion(array &$fila): void
    {
        $raw = trim((string) ($fila['valoracion_edad'] ?? ''));
        if ($raw === '' || !preg_match('/^(\d+(?:[.,]\d+)?)\s*[\/|\-]\s*(\d+(?:[.,]\d+)?)$/', $raw, $m)) {
            return;
        }

        if (!isset($fila['edad']) || $fila['edad'] === '') {
            $fila['edad'] = $this->formatearNumero($m[1]);
        }
        $fila['valoracion_edad'] = $this->formatearNumero($m[2]);
    }

    /**
     * @return list<string>
     */
    private function clavesRecurso(mixed $idGlpi, string $nombreEquipo): array
    {
        $claves = [];
        if ($idGlpi !== null && $idGlpi !== '') {
            $claves[] = $this->normalizarTexto((string) $idGlpi);
        }
        $nombreNorm = $this->normalizarTexto($nombreEquipo);
        if ($nombreNorm !== '') {
            $claves[] = $nombreNorm;
        }
        return array_values(array_unique(array_filter($claves)));
    }

    /**
     * @return list<string>
     */
    private function variantesRecurso(string $recursoNorm): array
    {
        $variantes = [$recursoNorm];
        if (preg_match('/^(\d+)/', $recursoNorm, $m)) {
            $variantes[] = $m[1];
        }
        return array_values(array_unique(array_filter($variantes)));
    }

    private function conceptoDesdePuntaje(mixed $puntaje): string
    {
        $n = $this->extraerNumero($puntaje);
        if ($n === null || $n <= 0) {
            return 'Obsoleto';
        }
        if ($n >= 100) {
            return 'Óptimo';
        }
        if ($n >= 60) {
            return 'Funcional';
        }
        return 'Potencializar';
    }

    private function normalizarConcepto(string $valor): string
    {
        $v = $this->normalizarTexto($valor);
        return match ($v) {
            'optimo', 'optima', 'optimos' => 'optimo',
            'funcional', 'funcionales' => 'funcional',
            'potencializar', 'potencialmente', 'potencial', 'potencializarle' => 'potencial',
            'obsoleto', 'obsoletos', 'obsoleta' => 'obsoleto',
            default => $v,
        };
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

    /**
     * Copia FECHA DE COMPRA, MODALIDAD DE COMPRA y MaxRam del Excel a los activos cruzados.
     *
     * @param  list<array{id_activo: int, fecha_compra?: string|null, modalidad?: string|null, max_ram?: string|int|float|null}>  $items
     * @return array{actualizados: int, omitidos: int, errores: int, detalle: list<array<string, mixed>>}
     */
    public function aplicarFechaYModalidad(array $items, Builder $queryActivos): array
    {
        $porId = [];
        foreach ($items as $item) {
            $id = (int) ($item['id_activo'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $porId[$id] = $item;
        }

        $ids = array_keys($porId);
        $actualizados = 0;
        $omitidos = 0;
        $errores = 0;
        $detalleResultado = [];
        $idsRecalcular = [];

        if ($ids === []) {
            return [
                'actualizados' => 0,
                'omitidos' => 0,
                'errores' => 0,
                'detalle' => [],
            ];
        }

        $activos = (clone $queryActivos)
            ->whereIn(self::tablaC() . '.id', $ids)
            ->with('detalle')
            ->get();

        $encontrados = [];

        foreach ($activos as $activo) {
            /** @var MatrizObsActivoC $activo */
            $encontrados[$activo->id] = true;
            $item = $porId[$activo->id];
            $fecha = $this->normalizarFecha($item['fecha_compra'] ?? '');
            $modalidad = trim((string) ($item['modalidad'] ?? ''));
            if (strcasecmp($modalidad, 'null') === 0) {
                $modalidad = '';
            }
            $maxRam = $this->extraerNumero($item['max_ram'] ?? '');

            if ($fecha === null && $modalidad === '' && $maxRam === null) {
                $omitidos++;
                $detalleResultado[] = [
                    'id_activo' => $activo->id,
                    'placa' => $activo->placa,
                    'accion' => 'omitido',
                    'motivo' => 'Sin fecha, modalidad ni MaxRam en el Excel',
                ];
                continue;
            }

            try {
                $detalle = $activo->detalle;
                if (!$detalle) {
                    $detalle = new MatrizObsActivoD(['activo_c_id' => $activo->id]);
                }

                $recalcular = false;
                if ($fecha !== null) {
                    $actual = $detalle->fecha_compra?->format('Y-m-d');
                    if ($actual !== $fecha) {
                        $detalle->fecha_compra = $fecha;
                        $recalcular = true;
                    }
                }
                if ($modalidad !== '') {
                    $detalle->modalidad = mb_strtoupper($modalidad, 'UTF-8');
                }
                if ($maxRam !== null) {
                    $actualMax = $detalle->max_ram !== null ? (float) $detalle->max_ram : null;
                    if ($actualMax === null || abs($actualMax - $maxRam) >= 0.01) {
                        $detalle->max_ram = $maxRam;
                        $recalcular = true;
                    }
                }

                $detalle->save();
                $actualizados++;
                if ($recalcular) {
                    $idsRecalcular[] = $activo->id;
                }

                $detalleResultado[] = [
                    'id_activo' => $activo->id,
                    'placa' => $activo->placa,
                    'accion' => 'actualizado',
                    'fecha_compra' => $fecha ?? '',
                    'modalidad' => $modalidad,
                    'max_ram' => $maxRam,
                ];
            } catch (\Throwable $e) {
                $errores++;
                $detalleResultado[] = [
                    'id_activo' => $activo->id,
                    'placa' => $activo->placa,
                    'accion' => 'error',
                    'motivo' => $e->getMessage(),
                ];
            }
        }

        foreach ($ids as $id) {
            if (!isset($encontrados[$id])) {
                $omitidos++;
                $detalleResultado[] = [
                    'id_activo' => $id,
                    'accion' => 'omitido',
                    'motivo' => 'Activo no encontrado o sin permiso',
                ];
            }
        }

        if ($idsRecalcular !== []) {
            try {
                app(\App\Services\MatrizObsolescenciaCalculatorService::class)
                    ->calcularValoresLote($idsRecalcular);
            } catch (\Throwable) {
                // La copia de fecha/modalidad ya quedó; el recálculo se puede hacer después.
            }
        }

        return [
            'actualizados' => $actualizados,
            'omitidos' => $omitidos,
            'errores' => $errores,
            'detalle' => $detalleResultado,
        ];
    }

    private static function tablaC(): string
    {
        return 'matzobs_activos_c';
    }
}
