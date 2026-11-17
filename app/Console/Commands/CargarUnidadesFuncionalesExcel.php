<?php

namespace App\Console\Commands;

use App\Models\Config\ConfigUnidadFuncional;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\Sucursal;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga unidades funcionales de Medilaser desde el Excel de nómina.
 *
 * Columnas esperadas:
 *   Empresa, Sucusal (o Sucursal), Code, UnidadFuncional, EstadoUnidad, EstadoCC
 *
 * Validar (no escribe):
 *   php artisan unidades:excel "C:\ruta\unidades.xlsx" validar
 *
 * Ejecutar (crea las que no existen y están bien):
 *   php artisan unidades:excel "C:\ruta\unidades.xlsx" ejecutar
 *
 * Ejecutar y además corregir nombre, sucursal o estado cuando el código ya existe:
 *   php artisan unidades:excel "C:\ruta\unidades.xlsx" ejecutar --corregir --force
 */
class CargarUnidadesFuncionalesExcel extends Command
{
    protected $signature = 'unidades:excel
                            {file : Ruta del Excel (.xlsx) de unidades funcionales}
                            {modo=validar : validar (no escribe) o ejecutar (crea las faltantes)}
                            {--empresa=1 : ID de empresa. 1 = Clinica Medilaser}
                            {--sheet=0 : Índice de la hoja (0 = primera)}
                            {--corregir : Al ejecutar, actualiza nombre, sucursal y estado si el código ya existe pero no coincide}
                            {--force : Ejecuta sin pedir confirmación}
                            {--reporte= : Ruta del CSV de resultado}';

    protected $description = 'Valida y carga unidades funcionales de Medilaser desde Excel, sin duplicar las que ya existen';

    public function handle(): int
    {
        $modo = strtolower(trim((string) $this->argument('modo')));
        if (!in_array($modo, ['validar', 'ejecutar'], true)) {
            $this->error('El modo debe ser "validar" o "ejecutar".');
            $this->line('  php artisan unidades:excel "archivo.xlsx" validar');
            $this->line('  php artisan unidades:excel "archivo.xlsx" ejecutar');
            return self::FAILURE;
        }

        $filePath = (string) $this->argument('file');
        if (!is_file($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");
            return self::FAILURE;
        }

        $empresa = Empresa::query()->find((int) $this->option('empresa'));
        if (!$empresa) {
            $this->error('No existe la empresa id ' . $this->option('empresa'));
            return self::FAILURE;
        }

        $sucursales = Sucursal::query()
            ->where('id_Empresa', $empresa->id)
            ->orderBy('nombre')
            ->get();

        if ($sucursales->isEmpty()) {
            $this->error("La empresa {$empresa->nombre} no tiene sucursales.");
            return self::FAILURE;
        }

        $sedes = Sede::query()
            ->whereIn('id_Sucursal', $sucursales->pluck('id'))
            ->orderBy('nombre')
            ->get();

        $existentes = ConfigUnidadFuncional::query()
            ->with(['sucursal:id,nombre', 'sede:id,nombre'])
            ->where('id_empresa', $empresa->id)
            ->get()
            ->keyBy(fn (ConfigUnidadFuncional $unidad) => mb_strtoupper(trim($unidad->codigo), 'UTF-8'));

        $this->info($modo === 'validar'
            ? 'Modo VALIDAR: no se escribe nada en la base de datos.'
            : 'Modo EJECUTAR: se crean solo las unidades nuevas que pasen la validación.');
        $this->line("Empresa: {$empresa->nombre} (id {$empresa->id})");
        $this->line('Sucursales reconocidas: ' . $sucursales->map(function (Sucursal $s) {
            $prefijo = $s->prefijo ? $s->prefijo . ' ' : '';
            return trim($prefijo . $s->nombre);
        })->implode(' | '));
        if ($sedes->isNotEmpty()) {
            $this->line('Sedes reconocidas: ' . $sedes->map(function (Sede $sede) use ($sucursales) {
                $padre = $sucursales->firstWhere('id', $sede->id_Sucursal);
                $de = $padre ? ' (' . $padre->nombre . ')' : '';
                return $sede->nombre . $de;
            })->implode(' | '));
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getSheet((int) $this->option('sheet'));
        } catch (\Throwable $e) {
            $this->error('No se pudo leer el Excel: ' . $e->getMessage());
            return self::FAILURE;
        }

        $rows = $sheet->toArray(null, true, true, true);
        $encabezado = $this->buscarEncabezado($rows);
        if ($encabezado === null) {
            $this->error('No se encontró la fila de títulos. Se esperan: Empresa, Sucusal, Code, UnidadFuncional, EstadoUnidad, EstadoCC.');
            $primera = null;
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $primera = $row;
                    break;
                }
            }
            if (is_array($primera)) {
                $leidos = array_values(array_filter(array_map(fn ($v) => $this->celdaTexto($v), $primera)));
                if ($leidos !== []) {
                    $this->line('Títulos leídos: ' . implode(' | ', $leidos));
                }
            }
            return self::FAILURE;
        }

        [$headerRow, $headerMap] = $encabezado;
        $this->line('Títulos en la fila ' . $headerRow . ': ' . implode(', ', array_keys($headerMap)));

        $resultado = [];
        $vistos = [];

        foreach ($rows as $numeroFila => $row) {
            if ((int) $numeroFila <= (int) $headerRow) {
                continue;
            }

            $empresaExcel = $this->valor($row, $headerMap, 'empresa');
            $sucursalExcel = $this->valor($row, $headerMap, 'sucursal');
            $codigoRaw = $this->valor($row, $headerMap, 'codigo');
            $nombreRaw = $this->valor($row, $headerMap, 'nombre');
            $estadoUnidadRaw = $this->valor($row, $headerMap, 'estado_unidad');
            $estadoCcRaw = $this->valor($row, $headerMap, 'estado_cc');

            if ($sucursalExcel === '' && $codigoRaw === '' && $nombreRaw === '') {
                continue;
            }

            $item = $this->clasificarFila(
                numeroFila: (int) $numeroFila,
                empresa: $empresa,
                empresaExcel: $empresaExcel,
                sucursalExcel: $sucursalExcel,
                codigoRaw: $codigoRaw,
                nombreRaw: $nombreRaw,
                estadoUnidadRaw: $estadoUnidadRaw,
                estadoCcRaw: $estadoCcRaw,
                sucursales: $sucursales,
                sedes: $sedes,
                existentes: $existentes,
                vistos: $vistos,
            );

            if ($item['codigo'] !== '' && $item['resultado'] !== 'DATO_MAL') {
                $vistos[$item['codigo']] = (int) $numeroFila;
            }

            $resultado[] = $item;
        }

        if ($resultado === []) {
            $this->warn('El Excel no tiene filas de datos.');
            return self::SUCCESS;
        }

        if ($modo === 'ejecutar') {
            $porCrear = count(array_filter($resultado, fn ($r) => $r['resultado'] === 'POR_CREAR'));
            $porCorregir = $this->option('corregir')
                ? count(array_filter($resultado, fn ($r) => $r['resultado'] === 'DATO_DISTINTO'))
                : 0;

            if ($porCrear === 0 && $porCorregir === 0) {
                $this->warn('No hay unidades nuevas para crear.');
            } elseif (!$this->option('force') && !$this->confirm($this->textoConfirmacion($porCrear, $porCorregir), false)) {
                $this->warn('Ejecución cancelada. No se escribió nada.');
                $modo = 'validar';
            } else {
                $this->aplicar($resultado, $empresa->id);
            }
        }

        $this->mostrarResumen($resultado, $modo);
        $reporte = $this->escribirReporte($resultado, $filePath);
        if ($reporte) {
            $this->info("Reporte: {$reporte}");
        }

        $hayMalos = count(array_filter($resultado, fn ($r) => $r['resultado'] === 'DATO_MAL')) > 0;
        return $hayMalos ? 2 : self::SUCCESS;
    }

    private function clasificarFila(
        int $numeroFila,
        Empresa $empresa,
        string $empresaExcel,
        string $sucursalExcel,
        string $codigoRaw,
        string $nombreRaw,
        string $estadoUnidadRaw,
        string $estadoCcRaw,
        $existentes,
        array $vistos,
        $sucursales,
        $sedes,
    ): array {
        $errores = [];
        $avisos = [];
        $codigo = mb_strtoupper(trim($codigoRaw), 'UTF-8');
        $nombre = trim(preg_replace('/\s+/', ' ', $nombreRaw) ?? '');

        if ($empresaExcel !== '' && !$this->empresaCoincide($empresaExcel, $empresa)) {
            $errores[] = "La empresa \"{$empresaExcel}\" no es {$empresa->nombre}";
        }

        if ($codigo === '') {
            $errores[] = 'Code vacío';
        } elseif (mb_strlen($codigo) > 20) {
            $errores[] = 'Code supera 20 caracteres (' . mb_strlen($codigo) . ')';
        } elseif (!preg_match('/^[A-Z0-9][A-Z0-9._\/\- ]{0,19}$/', $codigo)) {
            $errores[] = "Code con caracteres no permitidos: {$codigo}";
        }

        if ($nombre === '') {
            $errores[] = 'UnidadFuncional vacía';
        } elseif (mb_strlen($nombre) < 2) {
            $errores[] = 'UnidadFuncional demasiado corta';
        } elseif (mb_strlen($nombre) > 150) {
            $errores[] = 'UnidadFuncional supera 150 caracteres';
        }

        $sucursal = null;
        $sede = null;
        $sedePendiente = null;
        $sedePrefijo = null;
        $ubicacion = $this->resolverUbicacion($sucursalExcel, $sucursales, $sedes);
        if (isset($ubicacion['error'])) {
            $errores[] = $ubicacion['error'];
        } else {
            $sucursal = $ubicacion['sucursal'];
            $sede = $ubicacion['sede'];
            $sedePendiente = $ubicacion['sede_pendiente'] ?? null;
            $sedePrefijo = $ubicacion['sede_prefijo'] ?? null;
            if ($sedePendiente) {
                $avisos[] = "Sede {$sedePendiente} de Neiva no existe; se crea al ejecutar";
            }
        }

        $estado = $this->parseEstado($estadoUnidadRaw);
        $compararEstado = true;
        $activo = true;
        if (!empty($estado['invalido'])) {
            $errores[] = "EstadoUnidad no reconocido: {$estadoUnidadRaw}";
        } elseif (!empty($estado['vacio'])) {
            $avisos[] = 'EstadoUnidad vacío, se asume activo';
            $compararEstado = false;
            $activo = true;
        } else {
            $activo = (bool) $estado['activo'];
        }

        if ($estadoCcRaw !== '') {
            $estadoCc = $this->parseEstado($estadoCcRaw);
            if (!empty($estadoCc['invalido'])) {
                $avisos[] = "EstadoCC no reconocido: {$estadoCcRaw}";
            } elseif (empty($estadoCc['vacio']) && $estadoCc['activo'] === false && $activo) {
                $avisos[] = 'EstadoCC inactivo con unidad activa';
            }
        }

        if ($codigo !== '' && isset($vistos[$codigo])) {
            $errores[] = "Code repetido en el Excel (primera fila {$vistos[$codigo]})";
        }

        $base = [
            'fila' => $numeroFila,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'sucursal_excel' => $sucursalExcel,
            'sucursal_bd' => $sedePendiente
                ? trim($this->etiquetaUbicacion($sucursal, null) . ' / ' . $sedePendiente)
                : $this->etiquetaUbicacion($sucursal, $sede),
            'id_sucursal' => $sucursal?->id,
            'id_sede' => $sede?->id,
            'sede_pendiente' => $sedePendiente,
            'sede_prefijo' => $sedePrefijo,
            'cambia_sucursal' => false,
            'estado' => $activo,
            'estado_excel' => $estadoUnidadRaw,
            'avisos' => implode(' | ', $avisos),
            'detalle' => '',
            'unidad_id' => null,
        ];

        if ($errores !== []) {
            $base['resultado'] = 'DATO_MAL';
            $base['detalle'] = implode(' | ', $errores);
            return $base;
        }

        /** @var ConfigUnidadFuncional|null $actual */
        $actual = $existentes->get($codigo);
        if (!$actual) {
            $base['resultado'] = 'POR_CREAR';
            $base['detalle'] = $avisos === [] ? 'No existe, se puede crear' : implode(' | ', $avisos);
            return $base;
        }

        $base['unidad_id'] = $actual->id;
        $diferencias = [];
        if ($this->claveNombre($actual->nombre) !== $this->claveNombre($nombre)) {
            $diferencias[] = "Nombre en BD: {$actual->nombre} | Nombre en Excel: {$nombre}";
        }
        if ((int) $actual->id_sucursal !== (int) $sucursal->id) {
            $sucursalBd = $actual->sucursal?->nombre
                ?? Sucursal::query()->where('id', $actual->id_sucursal)->value('nombre')
                ?? (string) $actual->id_sucursal;
            $diferencias[] = "Sucursal en BD: {$sucursalBd} | Sucursal en Excel: {$sucursal->nombre}";
            $base['sucursal_bd'] = $sucursalBd;
            $base['cambia_sucursal'] = true;
        }
        if ($sedePendiente) {
            $sedeBd = $actual->sede?->nombre ?? 'sin sede';
            $diferencias[] = "Sede en BD: {$sedeBd} | Sede en Excel: {$sedePendiente} (sede de Sucursal Neiva)";
        } elseif ($sede && (int) ($actual->id_sede ?? 0) !== (int) $sede->id) {
            $sedeBd = $actual->sede?->nombre ?? 'sin sede';
            $diferencias[] = "Sede en BD: {$sedeBd} | Sede en Excel: {$sede->nombre}";
            $base['sucursal_bd'] = $this->etiquetaUbicacion($actual->sucursal, $actual->sede)
                . ' -> ' . $this->etiquetaUbicacion($sucursal, $sede);
        }
        if ($compararEstado && (bool) $actual->estado !== $activo) {
            $diferencias[] = 'Estado en BD: ' . ($actual->estado ? 'activo' : 'inactivo')
                . ' | Estado en Excel: ' . ($activo ? 'activo' : 'inactivo');
        }

        if ($diferencias === []) {
            $base['resultado'] = 'YA_EXISTE';
            $base['detalle'] = $avisos === [] ? 'Ya existe y coincide' : implode(' | ', $avisos);
            return $base;
        }

        $base['resultado'] = 'DATO_DISTINTO';
        $base['detalle'] = implode(' || ', $diferencias);
        return $base;
    }

    private function aplicar(array &$resultado, int $empresaId): void
    {
        $pendientes = array_filter($resultado, function (array $item) {
            return $item['resultado'] === 'POR_CREAR'
                || ($item['resultado'] === 'DATO_DISTINTO' && $this->option('corregir'));
        });

        $this->asegurarSedesPendientes($resultado);

        $bar = $this->output->createProgressBar(count($pendientes));
        $bar->start();

        foreach ($resultado as &$item) {
            try {
                if ($item['resultado'] === 'POR_CREAR') {
                    $creada = ConfigUnidadFuncional::create([
                        'id_empresa' => $empresaId,
                        'id_sucursal' => $item['id_sucursal'],
                        'id_sede' => $item['id_sede'],
                        'codigo' => $item['codigo'],
                        'nombre' => $item['nombre'],
                        'estado' => $item['estado'],
                    ]);
                    $item['resultado'] = 'CREADA';
                    $item['unidad_id'] = $creada->id;
                    $item['detalle'] = 'Creada. ' . $item['detalle'];
                    $bar->advance();
                    continue;
                }

                if ($item['resultado'] === 'DATO_DISTINTO' && $this->option('corregir')) {
                    $update = [
                        'id_sucursal' => $item['id_sucursal'],
                        'nombre' => $item['nombre'],
                        'estado' => $item['estado'],
                    ];
                    if (!empty($item['id_sede'])) {
                        $update['id_sede'] = $item['id_sede'];
                    } elseif (!empty($item['cambia_sucursal'])) {
                        $update['id_sede'] = null;
                    }
                    ConfigUnidadFuncional::query()->where('id', $item['unidad_id'])->update($update);
                    $item['resultado'] = 'CORREGIDA';
                    $item['detalle'] = 'Actualizada. ' . $item['detalle'];
                    $bar->advance();
                }
            } catch (QueryException $e) {
                $item['resultado'] = 'DATO_MAL';
                $item['detalle'] = str_contains($e->getMessage(), '1062')
                    ? 'No se pudo guardar: el código ya existe'
                    : 'No se pudo guardar: ' . mb_strimwidth($e->getMessage(), 0, 180, '...');
                $bar->advance();
            } catch (\Throwable $e) {
                $item['resultado'] = 'DATO_MAL';
                $item['detalle'] = 'No se pudo guardar: ' . $e->getMessage();
                $bar->advance();
            }
        }
        unset($item);

        $bar->finish();
        $this->newLine(2);
    }

    private function mostrarResumen(array $resultado, string $modo): void
    {
        $conteo = [];
        foreach ($resultado as $item) {
            $conteo[$item['resultado']] = ($conteo[$item['resultado']] ?? 0) + 1;
        }

        $filasResumen = [
            ['Ya existen y coinciden', $conteo['YA_EXISTE'] ?? 0],
            [$modo === 'ejecutar' ? 'Creadas' : 'Por crear', $modo === 'ejecutar' ? ($conteo['CREADA'] ?? 0) : ($conteo['POR_CREAR'] ?? 0)],
            ['Datos distintos (ya existe, no coincide)', $conteo['DATO_DISTINTO'] ?? 0],
            ['Datos malos (no se cargan)', $conteo['DATO_MAL'] ?? 0],
        ];
        if (($conteo['CORREGIDA'] ?? 0) > 0) {
            $filasResumen[] = ['Corregidas', $conteo['CORREGIDA']];
        }
        $filasResumen[] = ['Total filas', count($resultado)];

        $this->newLine();
        $this->table(['Resultado', 'Cantidad'], $filasResumen);

        if ($modo === 'validar' && ($conteo['POR_CREAR'] ?? 0) > 0) {
            $this->info('Por crear:');
            $this->table(
                ['Fila', 'Code', 'Unidad funcional', 'Sucursal', 'Estado'],
                collect($resultado)
                    ->where('resultado', 'POR_CREAR')
                    ->take(25)
                    ->map(fn ($r) => [
                        $r['fila'],
                        $r['codigo'],
                        mb_strimwidth($r['nombre'], 0, 40, '...'),
                        $r['sucursal_bd'],
                        $r['estado'] ? 'activo' : 'inactivo',
                    ])
                    ->values()
                    ->all()
            );
            if (($conteo['POR_CREAR'] ?? 0) > 25) {
                $this->line('  ... y ' . (($conteo['POR_CREAR']) - 25) . ' más en el reporte');
            }
        }

        $distintos = array_values(array_filter($resultado, fn ($r) => in_array($r['resultado'], ['DATO_DISTINTO', 'CORREGIDA'], true)));
        if ($distintos !== []) {
            $this->warn('Datos distintos respecto a lo ya cargado:');
            $this->table(
                ['Fila', 'Code', 'Detalle'],
                collect($distintos)->take(25)->map(fn ($r) => [
                    $r['fila'],
                    $r['codigo'],
                    mb_strimwidth($r['detalle'], 0, 90, '...'),
                ])->all()
            );
            if (count($distintos) > 25) {
                $this->line('  ... y ' . (count($distintos) - 25) . ' más en el reporte');
            }
            if ($modo === 'validar' || !$this->option('corregir')) {
                $this->line('Esas filas no se modifican. Para actualizarlas: ejecutar --corregir');
            }
        }

        $malos = array_values(array_filter($resultado, fn ($r) => $r['resultado'] === 'DATO_MAL'));
        if ($malos !== []) {
            $this->error('Datos malos:');
            $this->table(
                ['Fila', 'Code', 'Motivo'],
                collect($malos)->take(40)->map(fn ($r) => [
                    $r['fila'],
                    $r['codigo'],
                    mb_strimwidth($r['detalle'], 0, 90, '...'),
                ])->all()
            );
            if (count($malos) > 40) {
                $this->line('  ... y ' . (count($malos) - 40) . ' más en el reporte');
            }
        }
    }

    private function escribirReporte(array $resultado, string $filePath): ?string
    {
        $destino = trim((string) $this->option('reporte'));
        if ($destino === '') {
            $dir = storage_path('app/reportes');
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->warn('No se pudo crear la carpeta de reportes.');
                return null;
            }
            $destino = $dir . DIRECTORY_SEPARATOR . 'unidades_medilaser_' . now()->format('Ymd_His') . '.csv';
        } else {
            $dir = dirname($destino);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->warn("No se pudo crear la carpeta del reporte: {$dir}");
                return null;
            }
        }

        $fh = fopen($destino, 'wb');
        if ($fh === false) {
            $this->warn("No se pudo escribir el reporte: {$destino}");
            return null;
        }

        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['fila', 'resultado', 'code', 'unidad_funcional', 'sucursal_excel', 'sucursal', 'estado', 'detalle', 'avisos'], ';');
        foreach ($resultado as $item) {
            fputcsv($fh, [
                $item['fila'],
                $item['resultado'],
                $item['codigo'],
                $item['nombre'],
                $item['sucursal_excel'],
                $item['sucursal_bd'],
                $item['estado'] ? 'activo' : 'inactivo',
                $item['detalle'],
                $item['avisos'],
            ], ';');
        }
        fclose($fh);

        return $destino;
    }

    private function buscarEncabezado(array $rows): ?array
    {
        $revisadas = 0;
        foreach ($rows as $numeroFila => $row) {
            $revisadas++;
            if ($revisadas > 15) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $map = $this->mapearEncabezado($row);
            if (isset($map['codigo'], $map['nombre'], $map['sucursal'])) {
                return [(int) $numeroFila, $map];
            }
        }

        return null;
    }

    private function mapearEncabezado(array $row): array
    {
        $aliases = [
            'empresa' => 'empresa',
            'sucusa' => 'sucursal',
            'sucusal' => 'sucursal',
            'sucursa' => 'sucursal',
            'sucursl' => 'sucursal',
            'sucursal' => 'sucursal',
            'code' => 'codigo',
            'codigo' => 'codigo',
            'unidadfuncional' => 'nombre',
            'unidadfuncionales' => 'nombre',
            'nombre' => 'nombre',
            'estadounidad' => 'estado_unidad',
            'estado' => 'estado_unidad',
            'estadocc' => 'estado_cc',
            'estadocentrocosto' => 'estado_cc',
            'estadocentrodecosto' => 'estado_cc',
        ];

        $map = [];
        foreach ($row as $index => $cell) {
            $key = str_replace(' ', '', $this->normalizar($this->celdaTexto($cell)));
            if ($key === '') {
                continue;
            }
            $field = $aliases[$key] ?? (str_starts_with($key, 'sucu') ? 'sucursal' : null);
            if ($field === null || isset($map[$field])) {
                continue;
            }
            $map[$field] = $index;
        }

        return $map;
    }

    private function resolverUbicacion(string $texto, $sucursales, $sedes): array
    {
        $norm = $this->normalizar($texto);
        $norm = preg_replace('/^(sucursal|sede)\s+/', '', $norm) ?? $norm;
        if ($norm === '') {
            return ['error' => 'Sucursal vacía'];
        }

        $forzada = $this->resolverSedeDeNeiva($norm, $sucursales, $sedes);
        if ($forzada !== null) {
            return $forzada;
        }

        $candidatos = [];
        foreach ($sucursales as $sucursal) {
            $score = $this->puntajeUbicacion($norm, (string) $sucursal->nombre, $sucursal->prefijo);
            if ($score >= 70) {
                $candidatos[] = ['score' => $score, 'sucursal' => $sucursal, 'sede' => null];
            }
        }

        foreach ($sedes as $sede) {
            $score = $this->puntajeUbicacion($norm, (string) $sede->nombre, $sede->prefijo);
            if ($score < 70) {
                continue;
            }
            $padre = $sucursales->firstWhere('id', $sede->id_Sucursal);
            if (!$padre) {
                continue;
            }
            $candidatos[] = ['score' => $score, 'sucursal' => $padre, 'sede' => $sede];
        }

        if ($candidatos === []) {
            return ['error' => "Sucursal no existe en la empresa: {$texto}"];
        }

        $max = max(array_column($candidatos, 'score'));
        $top = array_values(array_filter($candidatos, fn (array $c) => $c['score'] === $max));
        $sucursalesTop = array_values(array_filter($top, fn (array $c) => $c['sede'] === null));
        $sedesTop = array_values(array_filter($top, fn (array $c) => $c['sede'] !== null));

        if (count($top) === 1) {
            return ['sucursal' => $top[0]['sucursal'], 'sede' => $top[0]['sede']];
        }
        if ($max >= 90 && count($sucursalesTop) === 1) {
            return ['sucursal' => $sucursalesTop[0]['sucursal'], 'sede' => null];
        }
        if (count($sedesTop) === 1) {
            return ['sucursal' => $sedesTop[0]['sucursal'], 'sede' => $sedesTop[0]['sede']];
        }

        return ['error' => "Sucursal ambigua: {$texto}"];
    }

    /**
     * Estas etiquetas de la columna Sucusal son sedes de Sucursal Neiva,
     * aunque existan sucursales con nombre parecido (Nacional, Abner Lozano, Myriam Parra).
     *
     * @return array{sucursal: Sucursal, sede: ?Sede, sede_pendiente?: string, sede_prefijo?: ?string}|array{error: string}|null
     */
    private function resolverSedeDeNeiva(string $norm, $sucursales, $sedes): ?array
    {
        $alias = [
            'abner' => ['Abner Lozano', 'EAL'],
            'abner lozano' => ['Abner Lozano', 'EAL'],
            'centro' => ['Centro', 'NVA'],
            'cnetro' => ['Centro', 'NVA'],
            'consulta externa' => ['Consulta Externa', 'NVA'],
            'consulta exterma' => ['Consulta Externa', 'NVA'],
            'nacional' => ['Sede nacional', 'NVA'],
            'sede nacional' => ['Sede nacional', 'NVA'],
            'miriam parra' => ['Myriam Parra', 'MYP'],
            'miryam parra' => ['Myriam Parra', 'MYP'],
            'myriam parra' => ['Myriam Parra', 'MYP'],
            'materno' => ['Materno Infantil', 'CMI'],
            'materno infantil' => ['Materno Infantil', 'CMI'],
        ][$norm] ?? null;

        if ($alias === null) {
            return null;
        }

        $neiva = null;
        foreach ($sucursales as $sucursal) {
            $corto = preg_replace('/^sucursal\s+/', '', $this->normalizar((string) $sucursal->nombre)) ?? '';
            $prefijo = $this->normalizar((string) $sucursal->prefijo);
            if ($corto === 'neiva' || $prefijo === 'nva') {
                $neiva = $sucursal;
                break;
            }
        }

        if (!$neiva) {
            return ['error' => 'No existe Sucursal Neiva para ubicar la sede ' . $alias[0]];
        }

        $buscada = $this->normalizar($alias[0]);
        foreach ($sedes as $sede) {
            if ((int) $sede->id_Sucursal !== (int) $neiva->id) {
                continue;
            }
            $nombre = $this->normalizar((string) $sede->nombre);
            $corto = preg_replace('/^sede\s+/', '', $nombre) ?? $nombre;
            if ($nombre === $buscada || $corto === $buscada) {
                return ['sucursal' => $neiva, 'sede' => $sede];
            }
        }

        return [
            'sucursal' => $neiva,
            'sede' => null,
            'sede_pendiente' => $alias[0],
            'sede_prefijo' => $alias[1],
        ];
    }

    private function asegurarSedesPendientes(array &$resultado): void
    {
        $porCrear = [];
        foreach ($resultado as $item) {
            $escribe = $item['resultado'] === 'POR_CREAR'
                || ($item['resultado'] === 'DATO_DISTINTO' && $this->option('corregir'));
            if (!$escribe || empty($item['sede_pendiente']) || empty($item['id_sucursal'])) {
                continue;
            }
            $porCrear[$item['id_sucursal'] . '|' . $item['sede_pendiente']] = [
                'id_sucursal' => $item['id_sucursal'],
                'nombre' => $item['sede_pendiente'],
                'prefijo' => $item['sede_prefijo'] ?? null,
            ];
        }

        if ($porCrear === []) {
            return;
        }

        $ids = [];
        foreach ($porCrear as $clave => $datos) {
            $sede = Sede::query()
                ->where('id_Sucursal', $datos['id_sucursal'])
                ->where('nombre', $datos['nombre'])
                ->first();
            if (!$sede) {
                $sede = Sede::create([
                    'nombre' => $datos['nombre'],
                    'prefijo' => $datos['prefijo'],
                    'id_Sucursal' => $datos['id_sucursal'],
                ]);
                $this->line("Sede creada: {$sede->nombre} en sucursal {$datos['id_sucursal']}");
            }
            $ids[$clave] = $sede->id;
        }

        foreach ($resultado as &$item) {
            if (empty($item['sede_pendiente'])) {
                continue;
            }
            $clave = $item['id_sucursal'] . '|' . $item['sede_pendiente'];
            if (isset($ids[$clave])) {
                $item['id_sede'] = $ids[$clave];
            }
        }
        unset($item);
    }

    private function puntajeUbicacion(string $buscado, string $nombre, ?string $prefijo): int
    {
        $nombreNorm = $this->normalizar($nombre);
        $corto = preg_replace('/^(sucursal|sede)\s+/', '', $nombreNorm) ?? $nombreNorm;
        $pref = $this->normalizar((string) $prefijo);

        if ($pref !== '' && $buscado === $pref) {
            return 100;
        }
        if ($buscado === $nombreNorm || $buscado === $corto) {
            return 90;
        }
        if (mb_strlen($buscado) >= 4 && preg_match('/^' . preg_quote($buscado, '/') . '(\s|$)/', $corto)) {
            return 70;
        }

        return 0;
    }

    private function etiquetaUbicacion(?Sucursal $sucursal, ?Sede $sede): string
    {
        if (!$sucursal) {
            return '';
        }
        if ($sede) {
            return $sucursal->nombre . ' / ' . $sede->nombre;
        }

        return $sucursal->nombre;
    }

    private function empresaCoincide(string $texto, Empresa $empresa): bool
    {
        $normalizado = $this->normalizar($texto);
        if ($normalizado === '' || $normalizado === (string) $empresa->id) {
            return true;
        }

        $nombre = $this->normalizar($empresa->nombre);
        $prefijo = $this->normalizar((string) $empresa->prefijo);
        if ($normalizado === $nombre || ($prefijo !== '' && $normalizado === $prefijo)) {
            return true;
        }

        return str_contains($normalizado, 'medilaser') && str_contains($nombre, 'medilaser');
    }

    /**
     * @return array{activo?: bool, vacio?: bool, invalido?: bool}
     */
    private function parseEstado(string $raw): array
    {
        $normalizado = $this->normalizar($raw);
        if ($normalizado === '') {
            return ['vacio' => true, 'activo' => true];
        }

        $activos = ['1', 'true', 'si', 's', 'activo', 'activa', 'a', 'vigente', 'habilitado', 'habilitada', 'abierto', 'abierta'];
        $inactivos = ['0', 'false', 'no', 'n', 'inactivo', 'inactiva', 'i', 'cerrado', 'cerrada', 'deshabilitado', 'deshabilitada', 'anulado', 'anulada'];

        if (in_array($normalizado, $activos, true)) {
            return ['activo' => true];
        }
        if (in_array($normalizado, $inactivos, true)) {
            return ['activo' => false];
        }

        return ['invalido' => true];
    }

    private function valor(array $row, array $headerMap, string $campo): string
    {
        if (!isset($headerMap[$campo])) {
            return '';
        }

        return $this->celdaTexto($row[$headerMap[$campo]] ?? null);
    }

    private function celdaTexto(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (floor($value) == $value) {
                return (string) (int) $value;
            }
            return trim((string) $value);
        }

        return trim((string) $value);
    }

    private function normalizar(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function claveNombre(string $nombre): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $nombre) ?? ''), 'UTF-8');
    }

    private function textoConfirmacion(int $porCrear, int $porCorregir): string
    {
        $partes = [];
        if ($porCrear > 0) {
            $partes[] = "crear {$porCrear}";
        }
        if ($porCorregir > 0) {
            $partes[] = "corregir {$porCorregir}";
        }

        return '¿Confirmar ' . implode(' y ', $partes) . ' unidades funcionales de Medilaser?';
    }
}
