<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\InvTrazActivo;
use App\Models\TipoInventario;
use App\Models\TrazabilidadActivo;
use App\Models\User;
use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\ODataParquetService;
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
     * ACTUALIZADO: Ahora trae EstadoActivo, Localizacion y Responsable correctamente
     * según los requerimientos del nuevo sistema de inventarios.
     *
     * La vista de Indigo puede cambiar el casing o el separador de sus columnas;
     * el frontend no debería romperse por eso.
     *
     * @var array<string, list<string>>
     */
    private const MAPA_CAMPOS = [
        'placa'           => ['Placa', 'NroPlaca', 'Numero_Placa'],
        'estado'          => ['EstadoActivo', 'Estado_Activo', 'Estado', 'EstadoBaja'],
        'articulo'        => ['Articulo', 'Articulo_Nombre', 'Descripcion', 'NombreArticulo'],
        'articulo_codigo' => ['CodigoArticulo', 'Codigo_Articulo', 'CodArticulo', 'Referencia'],
        'marca'           => ['Marca', 'NombreMarca'],
        'modelo'          => ['Modelo'],
        'serie'           => ['Serie', 'NroSerie', 'Numero_Serie'],
        'responsable'     => ['Responsable', 'NombreResponsable', 'Empleado'],
        'localizacion'    => ['Localizacion', 'Localización', 'Location', 'Ubicacion'],
        'sucursal'        => ['Sucursal', 'NombreSucursal', 'Sede', 'Branch'],
        'tipo_inventario' => ['TipoInventario', 'Tipo_Inventario', 'TipoDeInventario'],
        'estado_fisico'   => ['Estado_Fisico', 'EstadoFisico', 'Estado Fisico'],
        'observacion'     => ['Observacion', 'Observación', 'Observaciones'],
    ];

    public function __construct(
        private readonly GraphFabricGatewayService $gateway,
        private readonly ODataParquetService $parquet
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

        $exacto = in_array($campo, ['placa', 'serie'], true);

        // ── Camino principal: endpoint dedicado que filtra sobre el parquet ──
        // local con DuckDB (~90 ms, filtra en el servidor sin bajar la vista).
        $filtradas = $this->buscarConParquetFilter($user, $campo, $valor, $exacto, $limit);
        if ($filtradas !== null) {
            return [
                'success' => true,
                'data'    => $filtradas,
                'total'   => count($filtradas),
            ];
        }

        // ── Fallback 1: dataset completo en memoria (parquet paginado) ───────
        $dataset = $this->datasetMaestro($user);
        if ($dataset !== null) {
            $filtradas = $this->filtrarEnMemoria($dataset, $campo, $valor, $exacto, $limit);

            return [
                'success' => true,
                'data'    => $filtradas,
                'total'   => count($filtradas),
            ];
        }

        // ── Fallback 2: vista SQL en vivo vía /api/data/dynamic ──────────────
        $columna = $this->resolverColumnaBusqueda($user, $campo);
        if ($columna === null) {
            return [
                'success' => false,
                'message' => "La vista no expone una columna para buscar por '{$campo}'.",
                'code'    => 422,
            ];
        }

        $filtro = $exacto ? $valor : "%{$valor}%";

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

        $normalizadas = array_map(fn (array $fila) => $this->normalizar($fila), $resultado['data'] ?? []);

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
     * Filtra el dataset del maestro (ya normalizado) en memoria.
     *
     * @param  list<array<string, mixed>> $dataset
     * @return list<array<string, mixed>>
     */
    private function filtrarEnMemoria(array $dataset, string $campo, string $valor, bool $exacto, int $limit): array
    {
        $needle = mb_strtolower(trim($valor));
        $out    = [];

        foreach ($dataset as $activo) {
            $campoValor = mb_strtolower(trim((string) ($activo[$campo] ?? '')));

            $coincide = $exacto
                ? $campoValor === $needle
                : ($campoValor !== '' && str_contains($campoValor, $needle));

            if ($coincide) {
                $out[] = $activo;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
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
     * ACTUALIZADO: Ahora requiere tipo_inventario_id y valida la periodicidad antes de guardar.
     *
     * Guarda el snapshot del activo tal como está en Indigo en este momento,
     * para que el historial muestre "de qué a qué" cambió cada campo.
     *
     * Optimización: si el activo ya se buscó recientemente (< 10 min), se usa
     * el cache en lugar de ir de nuevo a Fabric. Esto reduce ~600ms por registro.
     *
     * @param  array<string, mixed> $datos Novedades ya validadas por el controller (debe incluir tipo_inventario_id)
     * @return array{success: bool, data?: InvTrazActivo, message?: string, code?: int}
     */
    public function registrarNovedad(User $user, array $datos): array
    {
        $placa = trim((string) ($datos['placa'] ?? ''));
        $tipoInventarioId = (int) ($datos['tipo_inventario_id'] ?? 0);

        if ($placa === '') {
            return ['success' => false, 'message' => 'La placa es obligatoria.', 'code' => 422];
        }

        if ($tipoInventarioId <= 0) {
            return ['success' => false, 'message' => 'El tipo de inventario es obligatorio.', 'code' => 422];
        }

        // Validar periodicidad ANTES de consultar Fabric
        $validacion = $this->validarPeriodicidad($placa, $tipoInventarioId);
        if (!$validacion['puede_registrar']) {
            return [
                'success' => false,
                'message' => $validacion['mensaje'] ?? 'No se puede registrar este activo en este momento.',
                'code' => 409, // Conflict
                'data' => $validacion['ultimo_registro'] ?? null,
            ];
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

        // El snapshot crudo (_raw) es clave para la trazabilidad. El dataset de
        // búsqueda lo omite por peso, así que si falta lo recuperamos con una
        // consulta puntual por placa a la vista SQL (una sola fila, rápido).
        if (empty($activo['_raw'])) {
            $puntual = $this->gateway->queryViewData($user, self::SCHEMA, self::VIEW, [
                'columns'    => [],
                'filters'    => [($this->resolverColumnaBusqueda($user, 'placa') ?? 'Placa') => $placa],
                'limit'      => 1,
                'offset'     => 0,
                'skip_count' => true,
            ]);
            if (($puntual['success'] ?? false) && !empty($puntual['data'][0])) {
                $activo = $this->normalizar($puntual['data'][0]);
            }
        }

        $novedades = $this->soloNovedadesConValor($datos);

        // Req. 3: la localización original viene de Indigo y se conserva como
        // referencia. La nueva localización solo se guarda cuando difiere.
        $localizacionOriginal = $this->limpiar($activo['localizacion'] ?? null);
        if (isset($novedades['novedad_localizacion'])) {
            $esIgual = $localizacionOriginal !== null
                && $this->localizacionesIguales($novedades['novedad_localizacion'], $localizacionOriginal);
            if ($esIgual) {
                unset($novedades['novedad_localizacion']);
            }
        }

        if ($novedades === [] && trim((string) ($datos['observacion'] ?? '')) === '') {
            return [
                'success' => false,
                'message' => 'Registre al menos una novedad o una observación.',
                'code'    => 422,
            ];
        }

        // Req. 7: clasificar el resultado antes de persistir
        $resultado = count($novedades) > 0 ? 'con_novedades' : 'sin_novedades';

        try {
            $registro = TrazabilidadActivo::create(array_merge($novedades, [
                'placa'                 => $placa,
                'tipo_inventario_id'    => $tipoInventarioId,
                'serie'                 => $activo['serie'] ?? null,
                'articulo_codigo'       => $activo['articulo_codigo'] ?? null,
                'articulo_nombre'       => $activo['articulo'] ?? null,
                'valores_origen'        => $activo['_raw'] ?? $activo,
                'localizacion_original' => $localizacionOriginal,
                'resultado_inventario'  => $resultado,
                'observacion'           => $this->limpiar($datos['observacion'] ?? null),
                'sucursal_origen'       => $activo['sucursal'] ?? null,
                'id_empresa'            => $datos['id_empresa'] ?? null,
                'id_sucursal'           => $datos['id_sucursal'] ?? null,
                'registrado_por'        => $user->id,
                'es_externo'            => false,
            ]));
        } catch (\Throwable $e) {
            Log::error('ActivoFijoService: error registrando novedad', [
                'placa'   => $placa,
                'tipo_inventario_id' => $tipoInventarioId,
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
            'tipo_inventario_id' => $tipoInventarioId,
            'traz_id'  => $registro->id,
            'user'     => $user->email,
            'cambios'  => $registro->contarNovedades(),
        ]);

        // Retornar con el mismo formato que usa el historial/trazabilidad
        return ['success' => true, 'data' => $this->formatearRegistroNuevo($registro->load('registrador:id,name,email', 'tipoInventario:id,nombre'))];
    }

    /**
     * Valida si un activo puede ser registrado según el tipo de inventario y su periodicidad.
     *
     * NUEVO: Implementa las reglas de negocio de periodicidad:
     * - Inventario General (anual): máximo 1 registro por activo al año
     * - Inventario Aleatorio (mensual): máximo 1 registro por activo al mes
     *
     * @param  string $placa Placa del activo
     * @param  int $tipoInventarioId ID del tipo de inventario
     * @param  \Carbon\Carbon|null $fecha Fecha de referencia (default: hoy)
     * @return array{puede_registrar: bool, mensaje?: string, ultimo_registro?: array}
     */
    public function validarPeriodicidad(string $placa, int $tipoInventarioId, ?\Carbon\Carbon $fecha = null): array
    {
        $fecha = $fecha ?? now();

        // Buscar el tipo de inventario
        $tipoInventario = TipoInventario::find($tipoInventarioId);

        if (!$tipoInventario) {
            return [
                'puede_registrar' => false,
                'mensaje' => 'Tipo de inventario no encontrado.',
            ];
        }

        // Verificar si el tipo está activo
        if (!$tipoInventario->activo) {
            return [
                'puede_registrar' => false,
                'mensaje' => "El tipo de inventario '{$tipoInventario->nombre}' está inactivo.",
            ];
        }

        // Si no hay restricción de periodicidad, siempre puede registrarse
        if ($tipoInventario->periodicidad === 'ninguna') {
            return ['puede_registrar' => true];
        }

        // Calcular el rango de fechas según la periodicidad
        [$desde, $hasta] = $tipoInventario->calcularRangoPeriodicidad($fecha);

        // Buscar si ya existe un registro en ese período
        $registroExistente = TrazabilidadActivo::where('placa', $placa)
            ->where('tipo_inventario_id', $tipoInventarioId)
            ->whereBetween('created_at', [$desde, $hasta])
            ->with('registrador:id,name')
            ->orderByDesc('created_at')
            ->first();

        if ($registroExistente) {
            $nombrePeriodo = match ($tipoInventario->periodicidad) {
                'anual' => 'año ' . $fecha->year,
                'semestral' => 'semestre ' . ceil($fecha->month / 6) . ' de ' . $fecha->year,
                'trimestral' => 'trimestre ' . $fecha->quarter . ' de ' . $fecha->year,
                'mensual' => $fecha->translatedFormat('F \\d\\e Y'),
                'semanal' => 'semana del ' . $desde->format('d/m') . ' al ' . $hasta->format('d/m/Y'),
                default => 'período actual',
            };

            return [
                'puede_registrar' => false,
                'mensaje' => "Este activo ya fue inventariado en el {$nombrePeriodo} con el tipo '{$tipoInventario->nombre}'.",
                'ultimo_registro' => [
                    'id' => $registroExistente->id,
                    'fecha' => $registroExistente->created_at->format('d/m/Y H:i'),
                    'registrado_por' => $registroExistente->registrador?->name ?? 'Usuario desconocido',
                ],
            ];
        }

        return ['puede_registrar' => true];
    }

    /**
     * Obtiene los tipos de inventario activos disponibles.
     * Retorna directamente el array para embeber en respuestas compuestas.
     *
     * @return list<array{id: int, nombre: string, periodicidad: string, periodicidad_nombre: string, descripcion: string|null, descripcion_restriccion: string}>
     */
    public function tiposInventarioLista(): array
    {
        return TipoInventario::activos()
            ->orderBy('nombre')
            ->get()
            ->map(fn (TipoInventario $tipo) => [
                'id'                      => $tipo->id,
                'nombre'                  => $tipo->nombre,
                'periodicidad'            => $tipo->periodicidad,
                'periodicidad_nombre'     => $tipo->periodicidad_nombre,
                'regla_validacion'        => $tipo->regla_validacion,
                'descripcion'             => $tipo->descripcion,
                'descripcion_restriccion' => $tipo->descripcion_restriccion,
                'activo'                  => $tipo->activo,
            ])
            ->all();
    }

    /**
     * @deprecated Usar tiposInventarioLista() para embeber en respuestas compuestas.
     */
    public function tiposInventario(): array
    {
        return ['success' => true, 'data' => $this->tiposInventarioLista()];
    }

    /**
     * Historial de novedades de un activo, más reciente primero.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(string $placa, int $limit = 100): array
    {
        return TrazabilidadActivo::porPlaca($placa)
            ->with('registrador:id,name,email', 'tipoInventario:id,nombre')
            ->recientes()
            ->limit($limit)
            ->get()
            ->map(fn (TrazabilidadActivo $t) => $this->formatearRegistroNuevo($t))
            ->all();
    }

    /**
     * Listado paginado de todas las tomas, con filtros opcionales.
     *
     * ACTUALIZADO: Incluye filtro por tipo de inventario, quita unidad_funcional.
     *
     * @param  array<string, mixed> $filtros
     */
    public function listar(array $filtros = [], int $porPagina = 25): array
    {
        $query = TrazabilidadActivo::with('registrador:id,name,email', 'tipoInventario:id,nombre')->recientes();

        if (!empty($filtros['placa'])) {
            $query->where('placa', 'like', '%' . $filtros['placa'] . '%');
        }

        if (!empty($filtros['estado_fisico'])) {
            $query->where('novedad_estado_fisico', $filtros['estado_fisico']);
        }

        if (!empty($filtros['tipo_inventario_id'])) {
            $query->where('tipo_inventario_id', (int) $filtros['tipo_inventario_id']);
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

        if (isset($filtros['es_externo'])) {
            $query->where('es_externo', (bool) $filtros['es_externo']);
        }

        // Req. 7: filtros adicionales del reporte consolidado
        if (!empty($filtros['responsable'])) {
            $query->where('novedad_responsable', 'like', '%' . $filtros['responsable'] . '%');
        }

        if (!empty($filtros['localizacion'])) {
            $loc = '%' . $filtros['localizacion'] . '%';
            $query->where(function ($q) use ($loc) {
                $q->where('novedad_localizacion', 'like', $loc)
                  ->orWhere('localizacion_original', 'like', $loc);
            });
        }

        if (!empty($filtros['resultado'])) {
            $query->where('resultado_inventario', $filtros['resultado']);
        }

        $paginador = $query->paginate($porPagina);

        return [
            'data' => collect($paginador->items())
                ->map(fn (TrazabilidadActivo $t) => $this->formatearRegistroNuevo($t))
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
     * ACTUALIZADO: Ahora agrupa por tipo de inventario en lugar de unidad funcional.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $inicioMes = now()->startOfMonth()->toDateString();

        $resultado = DB::table('inv_traz_activo')
            ->selectRaw('COUNT(*) as total_tomas')
            ->selectRaw('COUNT(DISTINCT placa) as activos_distintos')
            ->selectRaw("SUM(CASE WHEN novedad_estado_fisico = 'Dar de baja' THEN 1 ELSE 0 END) as para_baja")
            ->selectRaw("SUM(CASE WHEN novedad_estado_fisico = 'Para Reparacion' THEN 1 ELSE 0 END) as para_reparacion")
            ->selectRaw("SUM(CASE WHEN novedad_estado_fisico = 'En buen estado' THEN 1 ELSE 0 END) as en_buen_estado")
            ->selectRaw("SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as tomas_hoy")
            ->selectRaw("SUM(CASE WHEN es_externo = 1 THEN 1 ELSE 0 END) as externos")
            ->selectRaw("SUM(CASE WHEN resultado_inventario = 'con_novedades' THEN 1 ELSE 0 END) as con_novedades")
            ->selectRaw("SUM(CASE WHEN resultado_inventario = 'sin_novedades' THEN 1 ELSE 0 END) as sin_novedades")
            ->first();

        // Desglose por tipo de inventario del mes actual
        $porTipo = DB::table('inv_traz_activo')
            ->join('inv_tipos_inventario', 'inv_traz_activo.tipo_inventario_id', '=', 'inv_tipos_inventario.id')
            ->select('inv_tipos_inventario.nombre as tipo_inventario')
            ->selectRaw('COUNT(*) as tomas')
            ->selectRaw('COUNT(DISTINCT inv_traz_activo.placa) as activos')
            ->where('inv_traz_activo.created_at', '>=', $inicioMes)
            ->groupBy('inv_tipos_inventario.id', 'inv_tipos_inventario.nombre')
            ->orderByDesc('tomas')
            ->get()
            ->map(fn ($row) => [
                'tipo_inventario' => $row->tipo_inventario,
                'tomas'           => (int) $row->tomas,
                'activos'         => (int) $row->activos,
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
            'con_novedades'     => (int) ($resultado->con_novedades ?? 0),
            'sin_novedades'     => (int) ($resultado->sin_novedades ?? 0),
            'nuevos'            => (int) ($resultado->externos ?? 0),
            'por_tipo_inventario' => $porTipo,
        ];
    }

    // =========================================================================
    // REPORTE / EXPORTACIÓN
    // =========================================================================

    /**
     * Construye el query base del reporte de trazabilidad aplicando todos los
     * filtros del Req. 7. Reutilizado por listar() y los exportadores.
     *
     * @param  array<string, mixed> $filtros
     */
    private function construirQueryReporte(array $filtros = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = TrazabilidadActivo::with(['registrador:id,name,email', 'tipoInventario:id,nombre'])
            ->orderBy('created_at', 'desc');

        if (!empty($filtros['tipo_inventario_id'])) {
            $query->where('tipo_inventario_id', (int) $filtros['tipo_inventario_id']);
        }

        if (!empty($filtros['placa_exacta'])) {
            $query->where('placa', $filtros['placa_exacta']);
        } elseif (!empty($filtros['placa'])) {
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

        if (isset($filtros['es_externo']) && $filtros['es_externo'] !== '') {
            $query->where('es_externo', (bool) $filtros['es_externo']);
        }

        if (!empty($filtros['responsable'])) {
            $query->where('novedad_responsable', 'like', '%' . $filtros['responsable'] . '%');
        }

        if (!empty($filtros['localizacion'])) {
            $loc = '%' . $filtros['localizacion'] . '%';
            $query->where(function ($q) use ($loc) {
                $q->where('novedad_localizacion', 'like', $loc)
                  ->orWhere('localizacion_original', 'like', $loc);
            });
        }

        if (!empty($filtros['resultado'])) {
            $query->where('resultado_inventario', $filtros['resultado']);
        }

        return $query;
    }

    /**
     * Filas planas del reporte, base común para XLSX/CSV/PDF.
     *
     * @param  array<string, mixed> $filtros
     * @return list<array<string, string>>
     */
    private function filasReporte(array $filtros = []): array
    {
        return $this->construirQueryReporte($filtros)->get()->map(function (TrazabilidadActivo $r) {
            return [
                'placa'                 => (string) ($r->placa ?? ''),
                'articulo'              => (string) ($r->articulo_nombre ?? ''),
                'responsable'           => (string) ($r->novedad_responsable ?? ''),
                'localizacion_indigo'   => (string) ($r->localizacion_original ?? ''),
                'localizacion_encontrada' => (string) ($r->novedad_localizacion ?? ''),
                'tipo_inventario'       => (string) ($r->tipoInventario?->nombre ?? ''),
                'estado_fisico'         => (string) ($r->novedad_estado_fisico ?? ''),
                'fecha_toma'            => (string) ($r->created_at?->format('d/m/Y H:i') ?? ''),
                'usuario'               => (string) ($r->registrador?->name ?? ''),
                'resultado'             => (string) ($r->resultadoInventario()),
                'observacion'           => (string) ($r->observacion ?? ''),
            ];
        })->all();
    }

    /** Encabezados del detalle del reporte (Req. 7). */
    private const REPORTE_HEADERS = [
        'Placa', 'Descripción', 'Responsable', 'Localización Indigo',
        'Localización Encontrada', 'Tipo Inventario', 'Estado Físico',
        'Fecha de Toma', 'Usuario Inventorista', 'Resultado', 'Observación',
    ];

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
        // Trae todas las tomas que cumplen los filtros (tipo de inventario,
        // rango de fechas, responsable, etc.) y las agrupa por placa para armar
        // el historial de cada activo.
        $registros = $this->construirQueryReporte($filtros)
            ->orderBy('placa')
            ->orderBy('created_at')
            ->get()
            ->groupBy('placa');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $this->construirHojaHistorial($spreadsheet->getActiveSheet(), $registros, $filtros);
        $this->construirHojaDetalleTomas($spreadsheet->createSheet(), $registros);

        $spreadsheet->setActiveSheetIndex(0);

        $sufijo = !empty($filtros['placa_exacta'])
            ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $filtros['placa_exacta'])
            : now()->format('Y-m-d_His');
        $filename = 'historial_activo_' . $sufijo . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Exporta el historial (línea de tiempo) de UN activo concreto por placa.
     * Reutiliza el mismo Excel de historial filtrado a esa placa.
     */
    public function exportarHistorialActivo(string $placa, array $filtros = []): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Coincidencia exacta de placa; conservar otros filtros opcionales (fechas, tipo).
        unset($filtros['placa']);
        $filtros['placa_exacta'] = $placa;

        return $this->exportarExcel($filtros);
    }

    /**
     * Hoja 1 "Historial por activo": por cada activo una fila cabecera con la
     * información principal + los valores ORIGINALES de la vista de Indigo, y
     * debajo una fila por cada inventario (toma) con sus cambios anterior→nuevo.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, TrazabilidadActivo>> $porPlaca
     * @param  array<string, mixed> $filtros
     */
    private function construirHojaHistorial(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        \Illuminate\Support\Collection $porPlaca,
        array $filtros
    ): void {
        $sheet->setTitle('Historial por activo');

        $azul   = '4472C4';
        $gris   = 'E9EDF5';
        $blanco = 'FFFFFF';

        // ── Título + contexto del filtro aplicado ────────────────────────
        $sheet->setCellValue('A1', 'Historial de Inventario por Activo');
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => $azul]],
        ]);

        $sheet->setCellValue('A2', $this->descripcionFiltros($filtros));
        $sheet->mergeCells('A2:H2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('666666');

        $headers = [
            'Placa', 'Artículo', 'Serie', 'Código Art.',
            'Localización original (Indigo)', 'Estado (Indigo)',
            'Responsable (Indigo)', 'Sucursal (Indigo)',
        ];

        $tomaHeaders = [
            '#', 'Fecha toma', 'Tipo inventario', 'Resultado',
            'Campo', 'Valor anterior (Indigo)', 'Valor nuevo (novedad)',
            'Estado físico', 'Observación', 'Inventariador',
        ];

        $fila = 4;

        foreach ($porPlaca as $placa => $tomas) {
            /** @var TrazabilidadActivo $primera */
            $primera = $tomas->first();
            $origen  = is_array($primera->valores_origen) ? $primera->valores_origen : [];

            // ── Cabecera del activo (información principal + Indigo) ──────
            foreach ($headers as $i => $h) {
                $sheet->setCellValue([$i + 1, $fila], $h);
            }
            $sheet->getStyle([1, $fila, count($headers), $fila])->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $blanco]],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $azul],
                ],
            ]);
            $fila++;

            $sheet->setCellValue([1, $fila], (string) $placa);
            $sheet->setCellValue([2, $fila], $primera->articulo_nombre);
            $sheet->setCellValue([3, $fila], $primera->serie);
            $sheet->setCellValue([4, $fila], $primera->articulo_codigo);
            $sheet->setCellValue([5, $fila], $primera->localizacion_original ?? $this->valorSnapshot($origen, ['Localizacion', 'Ubicacion']));
            $sheet->setCellValue([6, $fila], $this->valorSnapshot($origen, ['EstadoActivo', 'Estado']));
            $sheet->setCellValue([7, $fila], $this->valorSnapshot($origen, ['Responsable', 'NombreResponsable']));
            $sheet->setCellValue([8, $fila], $primera->sucursal_origen ?? $this->valorSnapshot($origen, ['Sucursal', 'Sede']));
            $sheet->getStyle([1, $fila, count($headers), $fila])->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $gris],
                ],
            ]);
            $fila++;

            // ── Sub-encabezado de tomas ──────────────────────────────────
            foreach ($tomaHeaders as $i => $h) {
                $sheet->setCellValue([$i + 1, $fila], $h);
            }
            $sheet->getStyle([1, $fila, count($tomaHeaders), $fila])->applyFromArray([
                'font' => ['bold' => true, 'italic' => true, 'size' => 9, 'color' => ['rgb' => '333333']],
                'borders' => [
                    'bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN],
                ],
            ]);
            $fila++;

            // ── Línea de tiempo: una toma por bloque, en orden cronológico ───
            // Los datos comunes (#, fecha, tipo, resultado, inventariador) se
            // escriben solo en la primera fila del bloque; los cambios van
            // debajo. Un borde superior separa visualmente cada toma.
            $indice = 1;
            foreach ($tomas as $toma) {
                /** @var TrazabilidadActivo $toma */
                $cambios   = $toma->cambios();
                $filaInicio = $fila;

                if ($cambios === []) {
                    $this->escribirFilaToma($sheet, $fila, $indice, $toma, null, true);
                    $fila++;
                } else {
                    foreach ($cambios as $pos => $cambio) {
                        $this->escribirFilaToma($sheet, $fila, $indice, $toma, $cambio, $pos === 0);
                        $fila++;
                    }
                }

                // Borde superior en toda la fila inicial del bloque = separador de tomas
                $sheet->getStyle([1, $filaInicio, count($tomaHeaders), $filaInicio])
                    ->getBorders()->getTop()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_HAIR);

                $indice++;
            }

            // Fila en blanco separadora entre activos
            $fila++;
        }

        if ($porPlaca->isEmpty()) {
            $sheet->setCellValue('A4', 'No hay registros para los filtros seleccionados.');
            $sheet->getStyle('A4')->getFont()->setItalic(true)->getColor()->setRGB('999999');
        }

        foreach (range(1, count($tomaHeaders)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->freezePane('A4');
    }

    /**
     * Escribe una fila de toma. Si $cambio es null, es una toma sin cambios de
     * campo (se muestran solo los datos comunes de la toma).
     *
     * @param  array{campo: string, etiqueta: string, anterior: ?string, nuevo: string}|null $cambio
     */
    private function escribirFilaToma(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $fila,
        int $indice,
        TrazabilidadActivo $toma,
        ?array $cambio,
        bool $esPrimeraDelGrupo = true
    ): void {
        // Datos comunes de la toma: solo en la primera fila del bloque para que
        // se lea como una línea de tiempo (evita repetir fecha/tipo por cada cambio).
        if ($esPrimeraDelGrupo) {
            $sheet->setCellValue([1, $fila], $indice);
            $sheet->setCellValue([2, $fila], $toma->created_at?->format('d/m/Y H:i'));
            $sheet->setCellValue([3, $fila], $toma->tipoInventario?->nombre ?? 'N/A');
            $sheet->setCellValue([4, $fila], $this->etiquetaResultado($toma->resultadoInventario()));
            $sheet->setCellValue([9, $fila], $toma->observacion);
            $sheet->setCellValue([10, $fila], $toma->registrador?->name ?? 'N/A');
        }

        // Los cambios (campo / anterior / nuevo) y el estado físico van en cada fila.
        $sheet->setCellValue([5, $fila], $cambio['etiqueta'] ?? '(sin cambios)');
        $sheet->setCellValue([6, $fila], $cambio['anterior'] ?? '');
        $sheet->setCellValue([7, $fila], $cambio['nuevo'] ?? '');
        $sheet->setCellValue([8, $fila], $toma->novedad_estado_fisico);
    }

    /**
     * Hoja 2 "Detalle de tomas": una fila plana por toma (formato clásico) para
     * quien prefiera tabla dinámica / filtros de Excel.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, TrazabilidadActivo>> $porPlaca
     */
    private function construirHojaDetalleTomas(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        \Illuminate\Support\Collection $porPlaca
    ): void {
        $sheet->setTitle('Detalle de tomas');

        $headers = [
            'Placa', 'Artículo', 'Serie', 'Tipo Inventario', 'Resultado',
            'Localización Indigo', 'Localización Encontrada', 'Estado Físico',
            'Responsable', 'Observación', 'Inventariador', 'Fecha Toma',
        ];

        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, 1], $h);
        }
        $sheet->getStyle([1, 1, count($headers), 1])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
        ]);

        $row = 2;
        foreach ($porPlaca as $tomas) {
            foreach ($tomas as $t) {
                /** @var TrazabilidadActivo $t */
                $sheet->setCellValue([1, $row], $t->placa);
                $sheet->setCellValue([2, $row], $t->articulo_nombre);
                $sheet->setCellValue([3, $row], $t->serie);
                $sheet->setCellValue([4, $row], $t->tipoInventario?->nombre ?? 'N/A');
                $sheet->setCellValue([5, $row], $this->etiquetaResultado($t->resultadoInventario()));
                $sheet->setCellValue([6, $row], $t->localizacion_original);
                $sheet->setCellValue([7, $row], $t->novedad_localizacion);
                $sheet->setCellValue([8, $row], $t->novedad_estado_fisico);
                $sheet->setCellValue([9, $row], $t->novedad_responsable);
                $sheet->setCellValue([10, $row], $t->observacion);
                $sheet->setCellValue([11, $row], $t->registrador?->name ?? 'N/A');
                $sheet->setCellValue([12, $row], $t->created_at?->format('d/m/Y H:i'));
                $row++;
            }
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    /**
     * Busca un valor en el snapshot de Indigo probando varios nombres de columna
     * (la vista puede variar casing/separadores).
     *
     * @param  array<string, mixed> $origen
     * @param  list<string> $candidatos
     */
    private function valorSnapshot(array $origen, array $candidatos): string
    {
        $indice = [];
        foreach ($origen as $clave => $valor) {
            $indice[strtolower(str_replace(['_', ' ', '-'], '', (string) $clave))] = $valor;
        }

        foreach ($candidatos as $candidato) {
            $clave = strtolower(str_replace(['_', ' ', '-'], '', $candidato));
            if (isset($indice[$clave]) && $indice[$clave] !== null && $indice[$clave] !== '') {
                return (string) $indice[$clave];
            }
        }

        return '';
    }

    private function etiquetaResultado(string $resultado): string
    {
        return match ($resultado) {
            'con_novedades' => 'Con novedades',
            'sin_novedades' => 'Sin novedades',
            'externo'       => 'Activo nuevo (externo)',
            default         => $resultado,
        };
    }

    /**
     * Describe en texto los filtros aplicados para el encabezado del reporte.
     *
     * @param  array<string, mixed> $filtros
     */
    private function descripcionFiltros(array $filtros): string
    {
        $partes = [];

        if (!empty($filtros['tipo_inventario_id'])) {
            $tipo = TipoInventario::find((int) $filtros['tipo_inventario_id']);
            $partes[] = 'Tipo: ' . ($tipo?->nombre ?? $filtros['tipo_inventario_id']);
        }

        if (!empty($filtros['desde']) || !empty($filtros['hasta'])) {
            $desde = $filtros['desde'] ?? 'inicio';
            $hasta = $filtros['hasta'] ?? 'hoy';
            $partes[] = "Periodo: {$desde} a {$hasta}";
        }

        if (!empty($filtros['responsable'])) {
            $partes[] = 'Responsable: ' . $filtros['responsable'];
        }

        if (!empty($filtros['localizacion'])) {
            $partes[] = 'Localización: ' . $filtros['localizacion'];
        }

        if (!empty($filtros['resultado'])) {
            $partes[] = 'Resultado: ' . $this->etiquetaResultado((string) $filtros['resultado']);
        }

        $texto = $partes === [] ? 'Todos los inventarios' : implode('  |  ', $partes);

        return "Filtros: {$texto}   ·   Generado: " . now()->format('d/m/Y H:i');
    }

    // =========================================================================
    // EXPORTAR CSV
    // =========================================================================

    /**
     * Genera un CSV (UTF-8 con BOM) del reporte consolidado (Req. 7).
     *
     * @param  array<string, mixed> $filtros
     */
    public function exportarCsv(array $filtros = []): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filas    = $this->filasReporte($filtros);
        $filename = 'reporte_inventario_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($filas) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel abra UTF-8 correctamente
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::REPORTE_HEADERS, ';');
            foreach ($filas as $fila) {
                fputcsv($out, array_values($fila), ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type'  => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    // =========================================================================
    // EXPORTAR PDF
    // =========================================================================

    /**
     * Genera un PDF del reporte consolidado (Req. 7).
     *
     * Usa barryvdh/laravel-dompdf si está disponible; si no, degrada a una
     * descarga HTML imprimible para no bloquear el reporte.
     *
     * @param  array<string, mixed> $filtros
     */
    public function exportarPdf(array $filtros = []): \Symfony\Component\HttpFoundation\Response
    {
        $filas    = $this->filasReporte($filtros);
        $resumen  = $this->resumen();
        $filename = 'reporte_inventario_' . now()->format('Y-m-d_His') . '.pdf';

        $html = view('inventory.reporte_activos_pdf', [
            'filas'        => $filas,
            'headers'      => self::REPORTE_HEADERS,
            'resumen'      => $resumen,
            'filtros'      => $filtros,
            'filtrosTexto' => $this->descripcionFiltros($filtros),
            'generado'     => now()->format('d/m/Y H:i'),
        ])->render();

        // Preferir DomPDF si el paquete está instalado
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape');

            return response($pdf->output(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'max-age=0',
            ]);
        }

        // Degradación: HTML imprimible (el navegador puede "Guardar como PDF")
        Log::warning('ActivoFijoService: DomPDF no instalado, exportando PDF como HTML imprimible.');

        return response($html, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . str_replace('.pdf', '.html', $filename) . '"',
        ]);
    }

    // =========================================================================
    // NOVEDAD EXTERNA (activos que no están en el maestro)
    // =========================================================================

    /**
     * Registra un activo encontrado en campo que NO existe en el maestro de Indigo.
     *
     * ACTUALIZADO: Ahora requiere tipo_inventario_id.
     *
     * @param  array<string, mixed> $datos Datos ya validados por el controller
     * @return array{success: bool, data?: TrazabilidadActivo, message?: string, code?: int}
     */
    public function registrarNovedadExterna(User $user, array $datos): array
    {
        $placa = trim((string) ($datos['placa'] ?? ''));
        $tipoInventarioId = (int) ($datos['tipo_inventario_id'] ?? 0);

        if ($placa === '') {
            return ['success' => false, 'message' => 'La placa es obligatoria.', 'code' => 422];
        }

        if ($tipoInventarioId <= 0) {
            return ['success' => false, 'message' => 'El tipo de inventario es obligatorio.', 'code' => 422];
        }

        // Validar periodicidad
        $validacion = $this->validarPeriodicidad($placa, $tipoInventarioId);
        if (!$validacion['puede_registrar']) {
            return [
                'success' => false,
                'message' => $validacion['mensaje'] ?? 'No se puede registrar este activo en este momento.',
                'code' => 409,
                'data' => $validacion['ultimo_registro'] ?? null,
            ];
        }

        try {
            $registro = TrazabilidadActivo::create([
                'placa'                  => $placa,
                'tipo_inventario_id'     => $tipoInventarioId,
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
                'resultado_inventario'   => 'externo',
                'observacion'            => $this->limpiar($datos['observacion'] ?? null),
                'sucursal_origen'        => $this->limpiar($datos['sucursal'] ?? null),
                'es_externo'             => true,
                'id_empresa'             => $datos['id_empresa'] ?? null,
                'id_sucursal'            => $datos['id_sucursal'] ?? null,
                'registrado_por'         => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('ActivoFijoService: error registrando novedad externa', [
                'placa'   => $placa,
                'tipo_inventario_id' => $tipoInventarioId,
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
            'tipo_inventario_id' => $tipoInventarioId,
            'traz_id'  => $registro->id,
            'user'     => $user->email,
        ]);

        return ['success' => true, 'data' => $this->formatearRegistroNuevo($registro->load('registrador:id,name,email', 'tipoInventario:id,nombre'))];
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

    // =========================================================================
    // CATÁLOGOS PARA SELECTS DEL FRONTEND (contrato { valor: string })
    // =========================================================================

    /**
     * Localizaciones disponibles desde la vista de Indigo (Req. 3).
     *
     * Devuelve el contrato estable { valor: string } que consume el frontend
     * en el select de "nueva localización". Formato de valor:
     *   "N0101010102 - BODEGA ACTIVOS FIJOS ANTIGUA - Neiva"
     *
     * @return array{success: bool, data: list<array{valor: string}>}
     */
    public function localizaciones(User $user, string $busqueda = '', int $limit = 300): array
    {
        $busqueda = trim($busqueda);
        $cacheKey = 'activo_fijo:localizaciones:' . md5($busqueda . ':' . $limit);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'data' => $cached];
        }

        try {
            $todas = $this->obtenerLocalizacionesFabric($user);
        } catch (\Throwable $e) {
            Log::warning('ActivoFijoService: fallo obteniendo localizaciones', [
                'error' => $e->getMessage(),
            ]);
            $todas = [];
        }

        $data = collect($todas)
            ->filter(fn ($valor) => is_string($valor) && trim($valor) !== '')
            ->when($busqueda !== '', fn ($col) => $col->filter(
                fn ($valor) => mb_stripos($valor, $busqueda) !== false
            ))
            ->unique(fn ($valor) => mb_strtolower(trim($valor)))
            ->sort()
            ->values()
            ->take($limit)
            ->map(fn ($valor) => ['valor' => (string) $valor])
            ->all();

        Cache::put($cacheKey, $data, 600);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Responsables disponibles desde la vista de Indigo.
     *
     * Devuelve el contrato { valor: string } para el autocomplete de responsable.
     * La búsqueda es parcial sobre el nombre del responsable en DetalleActivos.
     *
     * @return array{success: bool, data: list<array{valor: string}>}
     */
    public function responsables(User $user, string $busqueda = '', int $limit = 50): array
    {
        $busqueda = trim($busqueda);
        if (mb_strlen($busqueda) < 2) {
            return ['success' => true, 'data' => []];
        }

        $cacheKey = 'activo_fijo:responsables:' . md5($busqueda . ':' . $limit);
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return ['success' => true, 'data' => $cached];
        }

        $columna = $this->resolverColumnaBusqueda($user, 'responsable') ?? 'Responsable';

        $resultado = $this->gateway->queryViewData($user, self::SCHEMA, self::VIEW, [
            'columns'    => [],
            'filters'    => [$columna => "%{$busqueda}%"],
            'limit'      => $limit * 4, // sobre-traer para deduplicar
            'offset'     => 0,
            'sort_col'   => '',
            'sort_dir'   => 'asc',
            'skip_count' => true,
        ]);

        if (!($resultado['success'] ?? false)) {
            return ['success' => true, 'data' => []];
        }

        $data = collect($resultado['data'] ?? [])
            ->map(fn (array $fila) => $this->normalizar($fila)['responsable'] ?? null)
            ->filter(fn ($valor) => is_string($valor) && trim($valor) !== '')
            ->unique(fn ($valor) => mb_strtolower(trim($valor)))
            ->sort()
            ->values()
            ->take($limit)
            ->map(fn ($valor) => ['valor' => (string) $valor])
            ->all();

        Cache::put($cacheKey, $data, 300);

        return ['success' => true, 'data' => $data];
    }

    // =========================================================================
    // CAMINO PARQUET (DuckDB — rápido, con fallback a la vista SQL)
    // =========================================================================

    /**
     * Valida acceso del usuario a la vista de activos (esquema + sede),
     * cacheado por usuario. Los permisos no cambian entre búsquedas, así que
     * cachearlos ahorra ~120 ms de consultas a BD por cada búsqueda.
     */
    private function usuarioTieneAcceso(User $user): bool
    {
        return Cache::remember(
            "activo_fijo:acceso:{$user->id}",
            300, // 5 min
            fn () => $this->gateway->tieneAccesoEsquema($user, self::SCHEMA)
                 && $this->gateway->tieneAccesoVistaPorSede($user, self::VIEW, self::SCHEMA)
        );
    }

    /**
     * Busca usando el endpoint dedicado de Graph-Fabric que filtra sobre el
     * parquet local (DuckDB). Filtra en el servidor: ~90 ms, sin traer la vista
     * completa a memoria.
     *
     * @return list<array<string, mixed>>|null  null si no está disponible (→ fallback)
     */
    private function buscarConParquetFilter(User $user, string $campo, string $valor, bool $exacto, int $limit): ?array
    {
        if (!config('fabric.activos_parquet', true)) {
            return null;
        }

        // Validar acceso (cacheado por usuario: ahorra ~120 ms por búsqueda).
        if (!$this->usuarioTieneAcceso($user)) {
            return null;
        }

        // Columna real en el parquet para el campo lógico buscado.
        $columna = $this->resolverColumnaBusqueda($user, $campo) ?? (self::CAMPOS_BUSQUEDA[$campo][0] ?? null);
        if ($columna === null) {
            return null;
        }

        // Igualdad exacta para identificadores; ILIKE parcial (%..%) para texto.
        $filtroValor = $exacto ? $valor : '%' . $valor . '%';

        try {
            $res = $this->parquet->filter(self::SCHEMA, self::VIEW, [$columna => $filtroValor], $limit, 0);
        } catch (\Throwable $e) {
            Log::warning('ActivoFijoService: fallo parquet-filter, usando fallback', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // 409 (sin parquet) o cualquier fallo → null para que el llamador use fallback.
        if (!($res['success'] ?? false)) {
            return null;
        }

        $normalizadas = [];
        foreach ($res['value'] ?? [] as $fila) {
            $activo = $this->normalizar($fila);
            $normalizadas[] = $activo;
            // Cachear por placa para acelerar el POST de novedad (con _raw completo).
            if (!empty($activo['placa'])) {
                Cache::put("activo_fijo:placa:{$activo['placa']}", $activo, 600);
            }
        }

        return $normalizadas;
    }

    /**
     * Devuelve el maestro completo de activos (normalizado) desde el parquet,
     * cacheado en Laravel. Reintenta paginando si hiciera falta.
     *
     * El endpoint parquet de Graph-Fabric (/api/data/odata) NO soporta $filter,
     * pero pagina el dataset completo muy rápido (≈700 ms para 55k filas). Como
     * el maestro es pequeño (3.5 MB) y cambia poco, lo traemos una vez, lo
     * normalizamos y lo cacheamos; las búsquedas filtran en memoria.
     *
     * @return list<array<string, mixed>>|null  null si el parquet no está disponible
     */
    private function datasetMaestro(User $user): ?array
    {
        if (!config('fabric.activos_parquet', true)) {
            return null;
        }

        // Validar acceso (cacheado por usuario).
        if (!$this->usuarioTieneAcceso($user)) {
            return null;
        }

        $ttl = (int) config('fabric.activos_busqueda_ttl', 1800);

        // El dataset (~55k filas) es demasiado grande para el cache de base de
        // datos; se persiste en un archivo local (rápido de leer/escribir).
        $rutaCache = storage_path('app/activos_dataset.cache');

        if ($ttl > 0 && is_file($rutaCache) && (time() - filemtime($rutaCache)) < $ttl) {
            $contenido = @file_get_contents($rutaCache);
            if ($contenido !== false) {
                $data = @unserialize($contenido);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        try {
            $page = $this->parquet->page(self::SCHEMA, self::VIEW, 0, 200000, [], false);
        } catch (\Throwable $e) {
            Log::warning('ActivoFijoService: fallo cargando dataset parquet', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // 409 = parquet aún no generado; null → el llamador usa fallback SQL.
        if (!($page['success'] ?? false)) {
            return null;
        }

        // Normalizar y descartar `_raw` (pesado y no se usa para buscar).
        $dataset = [];
        foreach ($page['value'] ?? [] as $fila) {
            $activo = $this->normalizar($fila);
            unset($activo['_raw']);
            $dataset[] = $activo;
        }

        if ($ttl > 0) {
            @file_put_contents($rutaCache, serialize($dataset), LOCK_EX);
        }

        return $dataset;
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
     * Compara dos localizaciones ignorando espacios extra y casing.
     * Sirve para decidir si la localización reportada difiere de la de Indigo (Req. 3).
     */
    private function localizacionesIguales(string $a, string $b): bool
    {
        $normalizar = static fn (string $v): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $v) ?? $v));

        return $normalizar($a) === $normalizar($b);
    }

    /**
     * Formatea un registro de trazabilidad para el frontend (modelo NUEVO TrazabilidadActivo).
     *
     * @return array<string, mixed>
     */
    private function formatearRegistroNuevo(TrazabilidadActivo $t): array
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
            'localizacion_original' => $t->localizacion_original,
            'tipo_inventario'  => [
                'id'     => $t->tipoInventario?->id,
                'nombre' => $t->tipoInventario?->nombre,
            ],
            'tipo_inventario_id' => $t->tipo_inventario_id,
            // Lista de cambios con valor anterior (Indigo) → valor nuevo reportado
            'cambios'          => $t->cambios(),
            'novedades'        => $t->resumen_novedades,
            'total_cambios'    => $t->contarNovedades(),
            'resultado'        => $t->resultadoInventario(),
            'registrado_por'   => [
                'id'     => $t->registrador?->id,
                'nombre' => $t->registrador?->name ?? 'Usuario eliminado',
                'email'  => $t->registrador?->email,
            ],
            'created_at'       => $t->created_at?->toIso8601String(),
            'created_at_human' => $t->created_at?->format('d/m/Y H:i'),
        ];
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
