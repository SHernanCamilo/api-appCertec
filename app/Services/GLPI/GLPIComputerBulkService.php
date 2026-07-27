<?php

namespace App\Services\GLPI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lectura masiva de activos de GLPI mediante /search + forcedisplay.
 *
 * Reemplaza el patrón de una llamada por campo y por equipo: una sola petición
 * trae hasta cientos de equipos con procesador, RAM, disco, SO y tag de agente
 * ya resueltos. Los IDs de campo están confirmados contra listSearchOptions.
 */
class GLPIComputerBulkService
{
    public const CAMPO = [
        'id' => 2,
        'nombre' => 1,
        'serial' => 5,
        'otherserial' => 6,
        'comentario' => 16,
        'ubicacion' => 3,
        'usuario' => 70,
        'tipo' => 4,
        'marca' => 23,
        'modelo' => 40,
        'sistema_operativo' => 45,
        'version_so' => 46,
        'procesador' => 17,
        'nucleos' => 18,
        'tipo_ram' => 110,
        'ram_total' => 111,
        'designacion_disco' => 114,
        'disco_total' => 115,
        'agente_tag' => 901,
        'date_mod' => 19,
    ];

    protected const TTL_CATALOGOS = 86400;

    protected ?array $catalogoDiscos = null;
    protected ?array $catalogoMemorias = null;
    protected ?array $directorioUsuarios = null;

    public function __construct(protected GLPIService $glpiService)
    {
    }

    public function contar(?string $modificadoDesde = null): int
    {
        $respuesta = $this->buscar(0, 2, $modificadoDesde);

        return (int) ($respuesta['totalcount'] ?? 0);
    }

    /**
     * Trae una página de equipos ya normalizados al formato que espera el mapeo.
     */
    public function traerPagina(int $offset, int $limite, ?string $modificadoDesde = null): array
    {
        $respuesta = $this->buscar($offset, $limite, $modificadoDesde);

        $equipos = [];
        foreach (array_slice($respuesta['data'] ?? [], 0, $limite) as $fila) {
            $equipo = $this->normalizar($fila);

            if ($equipo !== null) {
                $equipos[] = $equipo;
            }
        }

        return $equipos;
    }

    /**
     * Discos de un equipo con su interfaz ya resuelta contra el catálogo en memoria.
     *
     * Requiere una petición por equipo porque el endpoint global de
     * Item_DeviceHardDrive devuelve items_id en 0, y la designación del modelo no
     * sirve para deducir la interfaz: GLPI tiene el mismo modelo duplicado con
     * interfaces contradictorias (SATA, RAID e IDE a la vez).
     */
    public function discosDeEquipo(int $computerId): array
    {
        try {
            $items = $this->glpiService->get("/Computer/{$computerId}/Item_DeviceHardDrive");
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo discos del equipo {$computerId}", [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (empty($items) || !is_array($items)) {
            return [];
        }

        $catalogo = $this->catalogoDiscos();
        $discos = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $modelo = $catalogo[(int) ($item['deviceharddrives_id'] ?? 0)] ?? null;
            $capacidad = (int) ($item['capacity'] ?? 0);

            $discos[] = [
                'id' => $item['id'] ?? null,
                'capacity' => $capacidad,
                'capacity_mb' => $capacidad,
                'capacity_gb' => round($capacidad / 1024, 2),
                'serial' => $item['serial'] ?? null,
                'busID' => $item['busID'] ?? null,
                'designation' => $modelo['designation'] ?? null,
                'manufacturer' => $modelo['manufacturer'] ?? null,
                'interface' => $modelo['interface'] ?? null,
            ];
        }

        return $discos;
    }

    /**
     * Catálogo completo de modelos de disco: id => designación e interfaz.
     */
    public function catalogoDiscos(): array
    {
        if ($this->catalogoDiscos !== null) {
            return $this->catalogoDiscos;
        }

        $this->catalogoDiscos = Cache::remember('glpi_catalogo_discos', self::TTL_CATALOGOS, function () {
            $catalogo = [];

            foreach ($this->traerTodo('/DeviceHardDrive', ['expand_dropdowns' => true]) as $modelo) {
                $catalogo[(int) $modelo['id']] = [
                    'designation' => $modelo['designation'] ?? null,
                    // GLPI expande los dropdowns sin definir como el literal '0'
                    'manufacturer' => $this->sinCero($modelo['manufacturers_id'] ?? null),
                    'interface' => $this->sinCero($modelo['interfacetypes_id'] ?? null),
                ];
            }

            return $catalogo;
        });

        return $this->catalogoDiscos;
    }

    /**
     * Catálogo de módulos de memoria: designación => tipo de memoria.
     *
     * El campo 110 del search entrega la designación del módulo ("DDR4 - DIMM"),
     * mientras que la matriz guarda el tipo ("DDR4"), que vive en otra tabla. Este
     * catálogo hace la traducción sin pedir nada por equipo.
     */
    public function catalogoMemorias(): array
    {
        if ($this->catalogoMemorias !== null) {
            return $this->catalogoMemorias;
        }

        $this->catalogoMemorias = Cache::remember('glpi_catalogo_memorias', self::TTL_CATALOGOS, function () {
            $catalogo = [];

            foreach ($this->traerTodo('/DeviceMemory', ['expand_dropdowns' => true]) as $modelo) {
                $designacion = trim((string) ($modelo['designation'] ?? ''));
                $tipo = $modelo['devicememorytypes_id'] ?? null;

                // GLPI expande el tipo sin definir como el literal '0'
                if ($designacion === '' || $tipo === null || $tipo === '' || $tipo === '0' || $tipo === 0) {
                    continue;
                }

                $catalogo[$designacion] = $tipo;
            }

            return $catalogo;
        });

        return $this->catalogoMemorias;
    }

    /**
     * Directorio de usuarios: login => nombre completo, para no pedir /User por equipo.
     */
    public function directorioUsuarios(): array
    {
        if ($this->directorioUsuarios !== null) {
            return $this->directorioUsuarios;
        }

        $this->directorioUsuarios = Cache::remember('glpi_directorio_usuarios', self::TTL_CATALOGOS, function () {
            $directorio = [];

            foreach ($this->traerTodo('/User') as $usuario) {
                $login = $usuario['name'] ?? null;

                if (!$login) {
                    continue;
                }

                $nombreCompleto = trim(($usuario['firstname'] ?? '') . ' ' . ($usuario['realname'] ?? ''));
                $directorio[$login] = $nombreCompleto !== '' ? $nombreCompleto : null;
            }

            return $directorio;
        });

        return $this->directorioUsuarios;
    }

    public function limpiarCatalogos(): void
    {
        Cache::forget('glpi_catalogo_discos');
        Cache::forget('glpi_catalogo_memorias');
        Cache::forget('glpi_directorio_usuarios');
        $this->catalogoDiscos = null;
        $this->catalogoMemorias = null;
        $this->directorioUsuarios = null;
    }

    protected function buscar(int $offset, int $limite, ?string $modificadoDesde): array
    {
        // GLPI devuelve vacío cuando el rango pide una sola fila junto con criteria.
        $fin = $offset + max(2, $limite) - 1;

        $params = [
            'forcedisplay' => array_values(self::CAMPO),
            'range' => "{$offset}-{$fin}",
            'sort' => self::CAMPO['nombre'],
            'order' => 'ASC',
        ];

        if ($modificadoDesde) {
            $params['criteria'] = [[
                'field' => self::CAMPO['date_mod'],
                'searchtype' => 'morethan',
                'value' => $modificadoDesde,
            ]];
        }

        return $this->glpiService->get('/search/Computer', $params);
    }

    /**
     * Recorre un endpoint paginado hasta agotarlo.
     */
    protected function traerTodo(string $endpoint, array $params = [], int $porPagina = 1000, int $maxPaginas = 20): array
    {
        $todo = [];

        for ($pagina = 0; $pagina < $maxPaginas; $pagina++) {
            $desde = $pagina * $porPagina;
            $hasta = $desde + $porPagina - 1;

            $filas = $this->glpiService->get($endpoint, array_merge($params, ['range' => "{$desde}-{$hasta}"]));

            if (empty($filas) || !is_array($filas)) {
                break;
            }

            foreach ($filas as $fila) {
                if (is_array($fila)) {
                    $todo[] = $fila;
                }
            }

            if (count($filas) < $porPagina) {
                break;
            }
        }

        return $todo;
    }

    /**
     * Convierte una fila del search (claves numéricas) al formato que ya consumen
     * los extractores del comando de sincronización.
     */
    protected function normalizar(array $fila): ?array
    {
        $id = (int) $this->valor($fila, 'id');

        if ($id <= 0) {
            return null;
        }

        $equipo = [
            'id' => $id,
            'name' => $this->valor($fila, 'nombre'),
            'serial' => $this->valor($fila, 'serial'),
            'otherserial' => $this->valor($fila, 'otherserial'),
            'comment' => $this->valor($fila, 'comentario'),
            'locations_id' => $this->valor($fila, 'ubicacion'),
            'manufacturers_id' => $this->valor($fila, 'marca'),
            'computertypes_id' => $this->valor($fila, 'tipo'),
            'computermodels_id' => $this->valor($fila, 'modelo'),
            'date_mod' => $this->valor($fila, 'date_mod'),
            'devices' => [],
            '_pf' => ['origen' => 'bulk'],
        ];

        $ramTotal = (int) $this->valor($fila, 'ram_total');
        $tipoRam = $this->valor($fila, 'tipo_ram');

        // El campo 111 ya viene sumado por GLPI, así que se inyecta como un único
        // módulo para que extractRamSize obtenga el mismo total. Si la designación no
        // está en el catálogo, se deja el tipo en null y el extractor cae al camino
        // por API solo para ese equipo.
        if ($ramTotal > 0) {
            $equipo['devices']['Item_DeviceMemory'] = [[
                'size' => $ramTotal,
                'devicememorytypes_id_name' => $tipoRam !== null
                    ? ($this->catalogoMemorias()[$tipoRam] ?? null)
                    : null,
            ]];
        }

        $procesador = $this->valor($fila, 'procesador');
        $nucleos = (int) $this->valor($fila, 'nucleos');

        if ($procesador !== null || $nucleos > 0) {
            $equipo['devices']['Item_DeviceProcessor'] = [[
                'designation' => $procesador,
                'nbcores' => $nucleos > 0 ? $nucleos : null,
            ]];
        }

        $sistemaOperativo = $this->valor($fila, 'sistema_operativo');
        if ($sistemaOperativo !== null) {
            $equipo['_pf']['sistema_operativo'] = $sistemaOperativo;
        }

        $login = $this->valor($fila, 'usuario');
        if ($login !== null) {
            $equipo['_pf']['usuario_glpi'] = $this->directorioUsuarios()[$login] ?? null;
        }

        $tag = $this->valor($fila, 'agente_tag');
        if ($tag !== null) {
            $equipo['_pf']['agente'] = $tag;
        }

        return $equipo;
    }

    protected function sinCero($valor)
    {
        return ($valor === null || $valor === '' || $valor === '0' || $valor === 0) ? null : $valor;
    }

    /**
     * Los campos de componentes llegan como array cuando el equipo tiene varias
     * piezas (dos módulos de RAM, dos discos); se toma el primer valor útil.
     */
    protected function valor(array $fila, string $campo)
    {
        $id = self::CAMPO[$campo];
        $bruto = $fila[$id] ?? $fila[(string) $id] ?? null;

        if (is_array($bruto)) {
            foreach ($bruto as $item) {
                if ($item !== null && $item !== '') {
                    $bruto = $item;
                    break;
                }
            }

            if (is_array($bruto)) {
                return null;
            }
        }

        if ($bruto === null || $bruto === '' || $bruto === '0') {
            return null;
        }

        return $bruto;
    }
}
