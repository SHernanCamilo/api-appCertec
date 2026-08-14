<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Inventory\InvTrazActivo;
use App\Models\User;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Support\Collection;
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

        return [
            'success' => true,
            'data'    => array_map(fn (array $fila) => $this->normalizar($fila), $filas),
            'total'   => count($filas),
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
     * @param  array<string, mixed> $datos Novedades ya validadas por el controller
     * @return array{success: bool, data?: InvTrazActivo, message?: string, code?: int}
     */
    public function registrarNovedad(User $user, array $datos): array
    {
        $placa = trim((string) ($datos['placa'] ?? ''));

        if ($placa === '') {
            return ['success' => false, 'message' => 'La placa es obligatoria.', 'code' => 422];
        }

        // Traer el estado actual del activo para dejarlo como snapshot
        $consulta = $this->buscar($user, 'placa', $placa, 1);
        $activo   = ($consulta['success'] ?? false) ? ($consulta['data'][0] ?? null) : null;

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
            $registro = DB::transaction(function () use ($user, $placa, $activo, $novedades, $datos) {
                return InvTrazActivo::create(array_merge($novedades, [
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
            });
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

        return ['success' => true, 'data' => $registro->load('usuario:id,name,email')];
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
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $base = InvTrazActivo::query();

        return [
            'total_tomas'       => (clone $base)->count(),
            'activos_distintos' => (clone $base)->distinct('placa')->count('placa'),
            'para_baja'         => (clone $base)->where('novedad_estado_fisico', InvTrazActivo::ESTADO_FISICO_BAJA)->count(),
            'para_reparacion'   => (clone $base)->where('novedad_estado_fisico', InvTrazActivo::ESTADO_FISICO_REPARACION)->count(),
            'en_buen_estado'    => (clone $base)->where('novedad_estado_fisico', InvTrazActivo::ESTADO_FISICO_BUENO)->count(),
            'tomas_hoy'         => (clone $base)->whereDate('created_at', today())->count(),
        ];
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
     * Consulta las columnas reales para no fallar si Indigo renombró algo.
     */
    private function resolverColumnaBusqueda(User $user, string $campo): ?string
    {
        $candidatos = self::CAMPOS_BUSQUEDA[$campo];
        $columnas   = $this->columnas($user);

        if (!($columnas['success'] ?? false)) {
            // Sin metadata: se intenta con el primer candidato
            return $candidatos[0];
        }

        $reales = [];
        foreach ($columnas['data'] as $col) {
            $nombre = $col['name'] ?? '';
            if ($nombre !== '') {
                $reales[strtolower(str_replace(['_', ' ', '-'], '', $nombre))] = $nombre;
            }
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
            'id'              => $t->id,
            'placa'           => $t->placa,
            'serie'           => $t->serie,
            'articulo_codigo' => $t->articulo_codigo,
            'articulo_nombre' => $t->articulo_nombre,
            'observacion'     => $t->observacion,
            'sucursal_origen' => $t->sucursal_origen,
            'estado_fisico'   => $t->novedad_estado_fisico,
            'cambios'         => $t->cambios(),
            'total_cambios'   => count($t->cambios()),
            'registrado_por'  => [
                'id'     => $t->usuario?->id,
                'nombre' => $t->usuario?->name ?? 'Usuario eliminado',
                'email'  => $t->usuario?->email,
            ],
            'created_at'      => $t->created_at?->toIso8601String(),
            'created_at_human' => $t->created_at?->format('d/m/Y H:i'),
        ];
    }
}
