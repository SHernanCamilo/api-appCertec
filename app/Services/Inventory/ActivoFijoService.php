<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\InvTrazActivo;
use App\Models\User;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Toma de inventario de activos fijos.
 *
 * El maestro de activos vive en Indigo y se lee de solo lectura desde la vista
 * de Fabric `ra.VW_Fixed_DetalleActivos`. Este servicio:
 *
 *   - Busca activos en esa vista (por placa, serie, responsable o artículo)
 *   - Normaliza los nombres de columna a un contrato estable para el frontend
 *   - Guarda las novedades encontradas en sitio en `inv_traz_activo`
 *
 * Nunca escribe en Indigo: la novedad queda registrada aquí y el equipo
 * contable la aplica en el ERP con el soporte de la trazabilidad.
 */
class ActivoFijoService
{
    public const SCHEMA = 'ra';
    public const VIEW   = 'VW_Fixed_DetalleActivos';

    /**
     * Campos por los que se puede buscar un activo.
     * clave = valor que envía el frontend, valor = columnas candidatas en la vista.
     *
     * @var array<string, list<string>>
     */
    private const CAMPOS_BUSQUEDA = [
        'placa'       => ['Placa', 'placa', 'NroPlaca', 'Numero_Placa'],
        'serie'       => ['Serie', 'serie', 'NroSerie', 'Numero_Serie'],
        'responsable' => ['Responsable', 'responsable', 'NombreResponsable'],
        'articulo'    => ['Articulo', 'articulo', 'Articulo_Nombre', 'Descripcion'],
    ];

    /**
     * Contrato estable que consume el frontend → columnas candidatas en la vista.
     *
     * La vista de Indigo puede cambiar el casing o el separador de sus columnas;
     * el frontend no debería romperse por eso.
     *
     * @var array<string, list<string>>
     */
    private const MAPA_CAMPOS = [
        'placa'           => ['Placa', 'NroPlaca', 'Numero_Placa'],
        'estado'          => ['Estado', 'EstadoActivo', 'Estado_Activo'],
        'articulo'        => ['Articulo', 'Articulo_Nombre', 'Descripcion', 'NombreArticulo'],
        'articulo_codigo' => ['CodigoArticulo', 'Codigo_Articulo', 'CodArticulo', 'Referencia'],
        'marca'           => ['Marca', 'NombreMarca'],
        'modelo'          => ['Modelo'],
        'serie'           => ['Serie', 'NroSerie', 'Numero_Serie'],
        'responsable'     => ['Responsable', 'NombreResponsable'],
        'localizacion'    => ['Localizacion', 'Localización', 'Ubicacion', 'CentroCosto'],
        'tipo_inventario' => ['TipoInventario', 'Tipo_Inventario', 'TipoDeInventario'],
        'sucursal'        => ['Sucursal', 'NombreSucursal', 'Sede'],
        'estado_fisico'   => ['Estado_Fisico', 'EstadoFisico', 'Estado Fisico'],
        'observacion'     => ['Observacion', 'Observación', 'Observaciones'],
    ];

    public function __construct(
        private readonly GraphFabricGatewayService $gateway
    ) {}

    // =========================================================================
    // CONSULTA (Fabric — solo lectura)
    // =========================================================================

    /**
     * Busca activos en la vista de Indigo.
     *
     * @param  string $campo Una de las claves de CAMPOS_BUSQUEDA
     * @return array{success: bool, data?: list<array<string, mixed>>, total?: int, message?: string, code?: int}
     */
    public function buscar(User $user, string $campo, string $valor, int $limit = 50): array
    {
        if (!isset(self::CAMPOS_BUSQUEDA[$campo])) {
            return [
                'success' => false,
                'message' => "Campo de búsqueda no válido: '{$campo}'.",
                'code'    => 422,
            ];
        }

        $valor = trim($valor);
        if ($valor === '') {
            return ['success' => false, 'message' => 'Ingrese un valor para buscar.', 'code' => 422];
        }

        $columna = $this->resolverColumnaBusqueda($user, $campo);
        if ($columna === null) {
            return [
                'success' => false,
                'message' => "La vista no expone una columna para buscar por '{$campo}'.",
                'code'    => 422,
            ];
        }

        // Búsqueda exacta para placa/serie (son identificadores), parcial para el resto
        $filtro = in_array($campo, ['placa', 'serie'], true) ? $valor : "%{$valor}%";

        $resultado = $this->gateway->queryViewData($user, self::SCHEMA, self::VIEW, [
            'columns'    => [],
            'filters'    => [$columna => $filtro],
            'limit'      => $limit,
            'offset'     => 0,
            'sort_col'   => '',
            'sort_dir'   => 'asc',
            'skip_count' => true,
        ]);

        if (!($resultado['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $resultado['message'] ?? 'No se pudo consultar el maestro de activos.',
                'code'    => $resultado['code'] ?? 502,
            ];
        }

        $filas = $resultado['data'] ?? [];

        $normalizadas = array_map(fn (array $fila) => $this->normalizar($fila), $filas);

        // Cachear cada activo encontrado por placa (10 min) para que el POST
        // de novedad no tenga que ir de nuevo a Fabric
        foreach ($normalizadas as $activo) {
            if (!empty($activo['placa'])) {
                Cache::put("activo_fijo:placa:{$activo['placa']}", $activo, 600);
            }
        }

        return [
            'success' => true,
            'data'    => $normalizadas,
            'total'   => count($normalizadas),
        ];
    }

    /**
     * Detalle de un activo por placa, con su historial de novedades.
     *
     * @return array{success: bool, data?: array<string, mixed>, message?: string, code?: int}
     */
    public function detallePorPlaca(User $user, string $placa): array
    {
        $resultado = $this->buscar($user, 'placa', $placa, 1);

        if (!($resultado['success'] ?? false)) {
            return $resultado;
        }

        $activo = $resultado['data'][0] ?? null;

        if ($activo === null) {
            return [
                'success' => false,
                'message' => "No se encontró un activo con placa '{$placa}'.",
                'code'    => 404,
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'activo'    => $activo,
                'historial' => $this->historial($placa),
            ],
        ];
    }

    /**
     * Columnas reales de la vista. Sirve para que el frontend construya
     * selects dinámicos y para diagnosticar cambios en Indigo.
     *
     * @return array{success: bool, data?: list<array<string, mixed>>, message?: string, code?: int}
     */
    public function columnas(User $user): array
    {
        $resultado = $this->gateway->getViewColumns($user, self::SCHEMA, self::VIEW);

        if (!($resultado['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $resultado['message'] ?? 'No se pudieron obtener las columnas.',
                'code'    => $resultado['code'] ?? 502,
            ];
        }

        return ['success' => true, 'data' => $resultado['data']['columns'] ?? []];
    }

    // =========================================================================
    // TRAZABILIDAD (base local — escritura)
    // =========================================================================

    /**
     * Registra una novedad de toma de inventario.
     *
     * Guarda el snapshot del activo tal como está en Indigo en este momento,
     * para que el historial muestre "de qué a qué" cambió cada campo.
     *
     * Optimización: si el activo ya se buscó recientemente (< 10 min), se usa
     * el cache en lugar de ir de nuevo a Fabric. Esto reduce ~600ms por registro.
     *
     * @param  array<string, mixed> $datos Novedades ya validadas por el controller
     * @return array{success: bool, data?: InvTrazActivo, message?: string, code?: int}
     */
    public function registrarNovedad(User $user, array $datos): array
    {
        $placa = trim((string) ($datos['placa'] ?? ''));

        if ($placa === '') {
            return ['success' => false, 'message' => 'La placa es obligatoria.', 'code' => 422];
        }

        // Intentar cache (la búsqueda previa del frontend guardó el activo)
        $cacheKey = "activo_fijo:placa:{$placa}";
        $activo   = Cache::get($cacheKey);

        if ($activo === null) {
            $consulta = $this->buscar($user, 'placa', $placa, 1);
            $activo   = ($consulta['success'] ?? false) ? ($consulta['data'][0] ?? null) : null;
        }

        if ($activo === null) {
            return [
                'success' => false,
                'message' => "No se encontró el activo con placa '{$placa}' en el maestro de Indigo.",
                'code'    => 404,
            ];
        }

        $novedades = $this->soloNovedadesConValor($datos);

        if ($novedades === [] && trim((string) ($datos['observacion'] ?? '')) === '') {
            return [
                'success' => false,
                'message' => 'Registre al menos una novedad o una observación.',
                'code'    => 422,
            ];
        }

        try {
            $registro = InvTrazActivo::create(array_merge($novedades, [
                'placa'           => $placa,
                'serie'           => $activo['serie'] ?? null,
                'articulo_codigo' => $activo['articulo_codigo'] ?? null,
                'articulo_nombre' => $activo['articulo'] ?? null,
                'valores_origen'  => $activo['_raw'] ?? $activo,
                'observacion'     => $this->limpiar($datos['observacion'] ?? null),
                'sucursal_origen' => $activo['sucursal'] ?? null,
                'id_empresa'      => $datos['id_empresa'] ?? null,
                'id_sucursal'     => $datos['id_sucursal'] ?? null,
                'registrado_por'  => $user->id,
            ]));
        } catch (\Throwable $e) {
            Log::error('ActivoFijoService: error registrando novedad', [
                'placa'   => $placa,
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'No se pudo guardar la novedad. Intente de nuevo.',
                'code'    => 500,
            ];
        }

        Log::info('ActivoFijoService: novedad registrada', [
            'placa'    => $placa,
            'traz_id'  => $registro->id,
            'user'     => $user->email,
            'cambios'  => count($registro->cambios()),
        ]);

        // Retornar con el mismo formato que usa el historial/trazabilidad
        return ['success' => true, 'data' => $this->formatearRegistro($registro->load('usuario:id,name,email'))];
    }

    /**
     * Historial de novedades de un activo, más reciente primero.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(string $placa, int $limit = 100): array
    {
        return InvTrazActivo::dePlaca($placa)
            ->with('usuario:id,name,email')
            ->recientesPrimero()
            ->limit($limit)
            ->get()
            ->map(fn (InvTrazActivo $t) => $this->formatearRegistro($t))
            ->all();
    }

    /**
     * Listado paginado de todas las tomas, con filtros opcionales.
     *
     * @param  array<string, mixed> $filtros
     */
    public function listar(array $filtros = [], int $porPagina = 25): array
    {
        $query = InvTrazActivo::with('usuario:id,name,email')->recientesPrimero();

        if (!empty($filtros['placa'])) {
            $query->where('placa', 'like', '%' . $filtros['placa'] . '%');
        }

        if (!empty($filtros['estado_fisico'])) {
            $query->where('novedad_estado_fisico', $filtros['estado_fisico']);
        }

        if (!empty($filtros['usuario_id'])) {
            $query->where('registrado_por', (int) $filtros['usuario_id']);
        }

        if (!empty($filtros['desde'])) {
            $query->whereDate('created_at', '>=', $filtros['desde']);
        }

        if (!empty($filtros['hasta'])) {
            $query->whereDate('created_at', '<=', $filtros['hasta']);
        }

        if (!empty($filtros['unidad_funcional'])) {
            $query->where('unidad_funcional', $filtros['unidad_funcional']);
        }

        if (isset($filtros['es_externo'])) {
            $query->where('es_externo', (bool) $filtros['es_externo']);
        }

        $paginador = $query->paginate($porPagina);

        return [
            'data' => collect($paginador->items())
                ->map(fn (InvTrazActivo $t) => $this->formatearRegistro($t))
                ->all(),
            'meta' => [
                'total'        => $paginador->total(),
                'per_page'     => $paginador->perPage(),
                'current_page' => $paginador->currentPage(),
                'last_page'    => $paginador->lastPage(),
            ],
        ];
    }

    /**
     * Resumen para el tablero: cuántas tomas, cuántas piden baja, etc.
     * Optimizado: una sola query con aggregation condicional.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $inicioMes = now()->startOfMonth()->toDateString();

        $resultado = DB::table('inv_traz_activo')
            ->selectRaw('COUNT(*) as total_tomas')
            ->selectRaw('COUNT(DISTINCT placa) as activos_distintos')
            ->selectRaw("SUM(CASE WHEN novedad_estado_fisico = ? THEN 1 ELSE 0 END) as para_baja", [InvTrazActivo::ESTADO_FISICO_BAJA])
            ->selectRaw("SUM(CASE WHEN novedad_estado_fisico = ? THEN 1 ELSE 0 END) as para_reparacion", [InvTrazActivo::ESTADO_FISICO_REPARACION])
            ->selectRaw("SUM(CASE WHEN novedad_estado_fisico = ? THEN 1 ELSE 0 END) as en_buen_estado", [InvTrazActivo::ESTADO_FISICO_BUENO])
            ->selectRaw("SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as tomas_hoy")
            ->selectRaw("SUM(CASE WHEN es_externo = 1 THEN 1 ELSE 0 END) as externos")
            ->first();

        // Desglose por unidad funcional del mes actual
        $porUnidad = DB::table('inv_traz_activo')
            ->select('unidad_funcional')
            ->selectRaw('COUNT(*) as tomas')
            ->selectRaw('COUNT(DISTINCT placa) as activos')
            ->where('created_at', '>=', $inicioMes)
            ->whereNotNull('unidad_funcional')
            ->groupBy('unidad_funcional')
            ->orderByDesc('tomas')
            ->get()
            ->map(fn ($row) => [
                'unidad_funcional' => $row->unidad_funcional,
                'tomas'            => (int) $row->tomas,
                'activos'          => (int) $row->activos,
            ])
            ->all();

        return [
            'total_tomas'       => (int) ($resultado->total_tomas ?? 0),
            'activos_distintos' => (int) ($resultado->activos_distintos ?? 0),
            'para_baja'         => (int) ($resultado->para_baja ?? 0),
            'para_reparacion'   => (int) ($resultado->para_reparacion ?? 0),
            'en_buen_estado'    => (int) ($resultado->en_buen_estado ?? 0),
            'tomas_hoy'         => (int) ($resultado->tomas_hoy ?? 0),
            'externos'          => (int) ($resultado->externos ?? 0),
            'por_unidad_funcional' => $porUnidad,
        ];
    }

    // =========================================================================
    // EXPORTAR EXCEL
    // =========================================================================

    /**
     * Genera un archivo XLSX con la trazabilidad, clasificando inventariados
     * vs pendientes del mes actual.
     *
     * @param  array<string, mixed> $filtros
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportarExcel(array $filtros = []): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = InvTrazActivo::with('usuario:id,name,email')->recientesPrimero();

        if (!empty($filtros['unidad_funcional'])) {
            $query->where('unidad_funcional', $filtros['unidad_funcional']);
        }

        if (!empty($filtros['placa'])) {
            $query->where('placa', 'like', '%' . $filtros['placa'] . '%');
        }

        if (!empty($filtros['estado_fisico'])) {
            $query->where('novedad_estado_fisico', $filtros['estado_fisico']);
        }

        if (!empty($filtros['desde'])) {
            $query->whereDate('created_at', '>=', $filtros['desde']);
        }

        if (!empty($filtros['hasta'])) {
            $query->whereDate('created_at', '<=', $filtros['hasta']);
        }

        if (isset($filtros['es_externo'])) {
            $query->where('es_externo', (bool) $filtros['es_externo']);
        }

        $registros = $query->get();

        $inicioMes = now()->startOfMonth();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Trazabilidad');

        // Encabezados
        $headers = [
            'Placa', 'Serie', 'Artículo Código', 'Artículo Nombre',
            'Estado Físico', 'Localización', 'Sucursal Origen',
            'Unidad Funcional', 'Responsable', 'Observación',
            'Es Externo', 'Inventariado Este Mes', 'Registrado Por',
            'Fecha Registro',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        // Estilos encabezado
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
        ];
        $sheet->getStyle([1, 1, count($headers), 1])->applyFromArray($headerStyle);

        // Datos
        $row = 2;
        foreach ($registros as $registro) {
            $inventariadoEsteMes = $registro->created_at && $registro->created_at->gte($inicioMes) ? 'Sí' : 'No';

            $sheet->setCellValue([1, $row], $registro->placa);
            $sheet->setCellValue([2, $row], $registro->serie);
            $sheet->setCellValue([3, $row], $registro->articulo_codigo);
            $sheet->setCellValue([4, $row], $registro->articulo_nombre);
            $sheet->setCellValue([5, $row], $registro->novedad_estado_fisico);
            $sheet->setCellValue([6, $row], $registro->novedad_localizacion);
            $sheet->setCellValue([7, $row], $registro->sucursal_origen);
            $sheet->setCellValue([8, $row], $registro->unidad_funcional);
            $sheet->setCellValue([9, $row], $registro->novedad_responsable);
            $sheet->setCellValue([10, $row], $registro->observacion);
            $sheet->setCellValue([11, $row], $registro->es_externo ? 'Sí' : 'No');
            $sheet->setCellValue([12, $row], $inventariadoEsteMes);
            $sheet->setCellValue([13, $row], $registro->usuario?->name ?? 'N/A');
            $sheet->setCellValue([14, $row], $registro->created_at?->format('d/m/Y H:i'));

            $row++;
        }

        // Auto-size columns
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $filename = 'trazabilidad_activos_' . now()->format('Y-m-d_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    // =========================================================================
    // NOVEDAD EXTERNA (activos que no están en el maestro)
    // =========================================================================

    /**
     * Registra un activo encontrado en campo que NO existe en el maestro de Indigo.
     *
     * @param  array<string, mixed> $datos Datos ya validados por el controller
     * @return array{success: bool, data?: InvTrazActivo, message?: string, code?: int}
     */
    public function registrarNovedadExterna(User $user, array $datos): array
    {
        $placa = trim((string) ($datos['placa'] ?? ''));

        if ($placa === '') {
            return ['success' => false, 'message' => 'La placa es obligatoria.', 'code' => 422];
        }

        try {
            $registro = InvTrazActivo::create([
                'placa'                  => $placa,
                'serie'                  => $this->limpiar($datos['serie'] ?? null),
                'articulo_codigo'        => null,
                'articulo_nombre'        => $this->limpiar($datos['articulo_nombre'] ?? null),
                'valores_origen'         => null,
                'novedad_marca'          => $this->limpiar($datos['marca'] ?? null),
                'novedad_modelo'         => $this->limpiar($datos['modelo'] ?? null),
                'novedad_responsable'    => $this->limpiar($datos['responsable'] ?? null),
                'novedad_localizacion'   => $this->limpiar($datos['localizacion'] ?? null),
                'novedad_sucursal'       => $this->limpiar($datos['sucursal'] ?? null),
                'novedad_estado_fisico'  => $this->limpiar($datos['estado_fisico'] ?? null),
                'observacion'            => $this->limpiar($datos['observacion'] ?? null),
                'sucursal_origen'        => $this->limpiar($datos['sucursal'] ?? null),
                'es_externo'             => true,
                'unidad_funcional'       => $this->limpiar($datos['unidad_funcional'] ?? null),
                'id_empresa'             => $datos['id_empresa'] ?? null,
                'id_sucursal'            => $datos['id_sucursal'] ?? null,
                'registrado_por'         => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('ActivoFijoService: error registrando novedad externa', [
                'placa'   => $placa,
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'No se pudo guardar el activo externo. Intente de nuevo.',
                'code'    => 500,
            ];
        }

        Log::info('ActivoFijoService: novedad externa registrada', [
            'placa'    => $placa,
            'traz_id'  => $registro->id,
            'user'     => $user->email,
        ]);

        return ['success' => true, 'data' => $this->formatearRegistro($registro->load('usuario:id,name,email'))];
    }

    // =========================================================================
    // UNIDADES FUNCIONALES
    // =========================================================================

    /**
     * Devuelve la lista única de unidades funcionales combinando:
     * - novedad_localizacion de inv_traz_activo
     * - sucursal_origen de inv_traz_activo
     * - unidad_funcional de inv_traz_activo
     *
     * Opcionalmente también incluye las localizaciones de la vista de Fabric.
     *
     * @return array{success: bool, data: list<array{valor: string, origen: string}>}
     */
    public function unidadesFuncionales(User $user): array
    {
        $valores = collect();

        // Desde inv_traz_activo — novedad_localizacion
        $localizaciones = DB::table('inv_traz_activo')
            ->distinct()
            ->whereNotNull('novedad_localizacion')
            ->where('novedad_localizacion', '!=', '')
            ->pluck('novedad_localizacion');

        foreach ($localizaciones as $loc) {
            $valores->push(['valor' => $loc, 'origen' => 'trazabilidad_localizacion']);
        }

        // Desde inv_traz_activo — sucursal_origen
        $sucursales = DB::table('inv_traz_activo')
            ->distinct()
            ->whereNotNull('sucursal_origen')
            ->where('sucursal_origen', '!=', '')
            ->pluck('sucursal_origen');

        foreach ($sucursales as $suc) {
            $valores->push(['valor' => $suc, 'origen' => 'trazabilidad_sucursal']);
        }

        // Desde inv_traz_activo — unidad_funcional
        $unidades = DB::table('inv_traz_activo')
            ->distinct()
            ->whereNotNull('unidad_funcional')
            ->where('unidad_funcional', '!=', '')
            ->pluck('unidad_funcional');

        foreach ($unidades as $uf) {
            $valores->push(['valor' => $uf, 'origen' => 'trazabilidad_unidad_funcional']);
        }

        // Intentar incluir localizaciones de Fabric (si hay cache o la vista responde)
        try {
            $fabricLocalizaciones = $this->obtenerLocalizacionesFabric($user);
            foreach ($fabricLocalizaciones as $loc) {
                $valores->push(['valor' => $loc, 'origen' => 'fabric_maestro']);
            }
        } catch (\Throwable $e) {
            Log::warning('ActivoFijoService: no se pudieron obtener UFs de Fabric', [
                'error' => $e->getMessage(),
            ]);
        }

        // Deduplicar por valor (case-insensitive), mantener el primer origen
        $unicos = $valores
            ->unique(fn ($item) => mb_strtolower(trim($item['valor'])))
            ->sortBy(fn ($item) => mb_strtolower(trim($item['valor'])))
            ->values()
            ->all();

        return ['success' => true, 'data' => $unicos];
    }

    // =========================================================================
    // EMPLEADOS (Fabric: No.VW_Payroll_EmpleadosActivos)
    // =========================================================================

    /**
     * Busca empleados activos desde la vista de Fabric.
     * Devuelve solo documento y nombre completo para los selects de responsable.
     *
     * @param  string $busqueda Filtro parcial por nombre o documento
     * @return array{success: bool, data: list<array{documento: string, nombre: string}>}
     */
    public function empleados(User $user, string $busqueda = '', int $limit = 50): array
    {
        $cacheKey = 'activo_fijo:empleados:' . md5($busqueda . $limit);

        // Cache 5 min para búsquedas repetidas
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'data' => $cached];
        }

        $filters = [];
        if ($busqueda !== '') {
            // Buscar por nombre o documento (parcial)
            $filters['Nombres'] = "%{$busqueda}%";
        }

        $resultado = $this->gateway->queryViewData($user, 'No', 'VW_Payroll_EmpleadosActivos', [
            'columns'    => ['NumeroIdentificacion', 'Nombres', 'Apellidos'],
            'filters'    => $filters,
            'limit'      => $limit,
            'offset'     => 0,
            'sort_col'   => 'Apellidos',
            'sort_dir'   => 'asc',
            'skip_count' => true,
        ]);

        if (!($resultado['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $resultado['message'] ?? 'No se pudo consultar empleados.',
                'data'    => [],
            ];
        }

        $empleados = collect($resultado['data'] ?? [])
            ->map(function (array $fila) {
                $doc = $fila['NumeroIdentificacion'] ?? $fila['Numeroidentificacion'] ?? $fila['numeroidentificacion'] ?? '';
                $nombres = $fila['Nombres'] ?? $fila['nombres'] ?? '';
                $apellidos = $fila['Apellidos'] ?? $fila['apellidos'] ?? '';
                $nombreCompleto = trim("{$nombres} {$apellidos}");

                return [
                    'documento' => (string) $doc,
                    'nombre'    => $nombreCompleto,
                ];
            })
            ->filter(fn ($e) => $e['nombre'] !== '')
            ->unique('documento')
            ->values()
            ->all();

        Cache::put($cacheKey, $empleados, 300);

        return ['success' => true, 'data' => $empleados];
    }

    // =========================================================================
    // CENTROS DE COSTO / UNIDADES FUNCIONALES (Fabric: cp.VW_Payroll_UnidadFuncionales_CC)
    // =========================================================================

    /**
     * Obtiene los centros de costo (Unidades Funcionales) desde Fabric.
     * Devuelve code y UnidadFuncional para poblar el select de localización.
     *
     * @return array{success: bool, data: list<array{code: string, unidad_funcional: string}>}
     */
    public function centrosCosto(User $user): array
    {
        // Cache 30 min — estos datos cambian poco
        $cached = Cache::get('activo_fijo:centros_costo');
        if ($cached !== null) {
            return ['success' => true, 'data' => $cached];
        }

        $resultado = $this->gateway->queryViewData($user, 'cp', 'VW_Payroll_UnidadFuncionales_CC', [
            'columns'    => ['code', 'UnidadFuncional'],
            'filters'    => [],
            'limit'      => 1000,
            'offset'     => 0,
            'sort_col'   => 'UnidadFuncional',
            'sort_dir'   => 'asc',
            'skip_count' => true,
        ]);

        if (!($resultado['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $resultado['message'] ?? 'No se pudieron obtener los centros de costo.',
                'data'    => [],
            ];
        }

        $centros = collect($resultado['data'] ?? [])
            ->map(function (array $fila) {
                $code = $fila['code'] ?? $fila['Code'] ?? '';
                $uf = $fila['UnidadFuncional'] ?? $fila['unidadfuncional'] ?? $fila['Unidadfuncional'] ?? '';

                return [
                    'code'             => (string) $code,
                    'unidad_funcional' => (string) $uf,
                ];
            })
            ->filter(fn ($c) => $c['unidad_funcional'] !== '')
            ->unique('code')
            ->values()
            ->all();

        Cache::put('activo_fijo:centros_costo', $centros, 1800);

        return ['success' => true, 'data' => $centros];
    }

    /**
     * Obtiene las localizaciones únicas de la vista de Fabric.
     * Cachea por 30 min para no saturar las queries a Fabric.
     *
     * @return list<string>
     */
    private function obtenerLocalizacionesFabric(User $user): array
    {
        return Cache::remember('activo_fijo:uf_fabric', 1800, function () use ($user) {
            $resultado = $this->gateway->queryViewData($user, self::SCHEMA, self::VIEW, [
                'columns'    => [],
                'filters'    => [],
                'limit'      => 5000,
                'offset'     => 0,
                'sort_col'   => '',
                'sort_dir'   => 'asc',
                'skip_count' => true,
            ]);

            if (!($resultado['success'] ?? false)) {
                return [];
            }

            $localizaciones = collect($resultado['data'] ?? [])
                ->map(function (array $fila) {
                    $normalizado = $this->normalizar($fila);
                    return $normalizado['localizacion'] ?? null;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $localizaciones;
        });
    }

    // =========================================================================
    // INTERNOS
    // =========================================================================

    /**
     * Traduce una fila de la vista al contrato estable del frontend.
     * Conserva la fila original en `_raw` para el snapshot de auditoría.
     *
     * @param  array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function normalizar(array $fila): array
    {
        // Índice insensible a casing/separadores: "Estado_Fisico" → "estadofisico"
        $indice = [];
        foreach ($fila as $clave => $valor) {
            $indice[strtolower(str_replace(['_', ' ', '-'], '', (string) $clave))] = $valor;
        }

        $normalizado = [];
        foreach (self::MAPA_CAMPOS as $destino => $candidatos) {
            $normalizado[$destino] = null;

            foreach ($candidatos as $candidato) {
                $clave = strtolower(str_replace(['_', ' ', '-'], '', $candidato));
                if (array_key_exists($clave, $indice) && $indice[$clave] !== null && $indice[$clave] !== '') {
                    $normalizado[$destino] = $indice[$clave];
                    break;
                }
            }
        }

        $normalizado['_raw'] = $fila;

        return $normalizado;
    }

    /**
     * Resuelve qué columna de la vista usar para buscar por un campo lógico.
     * Cachea las columnas reales por 1 hora (la vista no cambia frecuentemente).
     */
    private function resolverColumnaBusqueda(User $user, string $campo): ?string
    {
        $candidatos = self::CAMPOS_BUSQUEDA[$campo];

        // Las columnas de la vista de Indigo no cambian en meses — cachear 1h
        $reales = Cache::remember('activo_fijo:columnas_vista', 3600, function () use ($user) {
            $columnas = $this->gateway->getViewColumns($user, self::SCHEMA, self::VIEW);

            if (!($columnas['success'] ?? false)) {
                return null;
            }

            $mapa = [];
            foreach ($columnas['data']['columns'] ?? [] as $col) {
                $nombre = $col['name'] ?? '';
                if ($nombre !== '') {
                    $mapa[strtolower(str_replace(['_', ' ', '-'], '', $nombre))] = $nombre;
                }
            }
            return $mapa;
        });

        if ($reales === null) {
            return $candidatos[0];
        }

        foreach ($candidatos as $candidato) {
            $clave = strtolower(str_replace(['_', ' ', '-'], '', $candidato));
            if (isset($reales[$clave])) {
                return $reales[$clave];
            }
        }

        return null;
    }

    /**
     * Deja solo los campos de novedad que traen valor real.
     * Un select en "--Seleccione--" llega vacío y no debe guardarse.
     *
     * @param  array<string, mixed> $datos
     * @return array<string, string>
     */
    private function soloNovedadesConValor(array $datos): array
    {
        $novedades = [];

        foreach (array_keys(InvTrazActivo::CAMPOS_NOVEDAD) as $campo) {
            $valor = $this->limpiar($datos[$campo] ?? null);
            if ($valor !== null) {
                $novedades[$campo] = $valor;
            }
        }

        return $novedades;
    }

    private function limpiar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        // Los selects sin elegir mandan el placeholder
        if ($texto === '' || str_starts_with($texto, '--')) {
            return null;
        }

        return $texto;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatearRegistro(InvTrazActivo $t): array
    {
        return [
            'id'               => $t->id,
            'placa'            => $t->placa,
            'serie'            => $t->serie,
            'articulo_codigo'  => $t->articulo_codigo,
            'articulo_nombre'  => $t->articulo_nombre,
            'observacion'      => $t->observacion,
            'sucursal_origen'  => $t->sucursal_origen,
            'estado_fisico'    => $t->novedad_estado_fisico,
            'es_externo'       => (bool) $t->es_externo,
            'unidad_funcional' => $t->unidad_funcional,
            'cambios'          => $t->cambios(),
            'total_cambios'    => count($t->cambios()),
            'registrado_por'   => [
                'id'     => $t->usuario?->id,
                'nombre' => $t->usuario?->name ?? 'Usuario eliminado',
                'email'  => $t->usuario?->email,
            ],
            'created_at'       => $t->created_at?->toIso8601String(),
            'created_at_human' => $t->created_at?->format('d/m/Y H:i'),
        ];
    }
}
