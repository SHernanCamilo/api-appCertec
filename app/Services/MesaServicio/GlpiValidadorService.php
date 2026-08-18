<?php

declare(strict_types=1);

namespace App\Services\MesaServicio;

use App\Models\MesaServicio\GlpiParamPlantilla;
use App\Models\MesaServicio\GlpiParamPlantillaAns;
use App\Models\MesaServicio\GlpiParamPlantillaCategoria;
use App\Services\GLPI\GLPIService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GlpiValidadorService
{
    private const CACHE_ENTIDADES = 'mesa_glpi_validador_entidades';
    private const CACHE_TTL_MINUTOS = 5;

    public function __construct(private GLPIService $glpi)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function arbolEntidades(): array
    {
        $this->asegurarConfiguracion();
        $this->activarEntidadRaiz();

        $cached = Cache::get(self::CACHE_ENTIDADES);
        if (is_array($cached) && $this->contarNodosArbol($cached) <= 1) {
            Cache::forget(self::CACHE_ENTIDADES);
        }

        return Cache::remember(self::CACHE_ENTIDADES, now()->addMinutes(self::CACHE_TTL_MINUTOS), function () {
            $filas = $this->cargarEntidades();

            return $this->armarArbolEntidades($filas);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function comparar(int $plantillaId, int $entidadId): array
    {
        $this->asegurarConfiguracion();
        $this->activarEntidadRaiz();

        try {
            $plantilla = GlpiParamPlantilla::with(['ans', 'categorias'])->find($plantillaId);
            if (! $plantilla) {
                throw new RuntimeException('Plantilla no encontrada.');
            }

            $entidades = $this->cargarEntidades();
            $entidad = collect($entidades)->first(fn ($item) => (int) ($item['id'] ?? -1) === $entidadId);
            if (! $entidad) {
                throw new RuntimeException('La entidad no existe en GLPI o no está visible para la sesión API.');
            }

            $rutaEntidad = (string) ($entidad['completename'] ?? $entidad['name'] ?? '');
            $categoriasGlpi = $this->cargarCategoriasDeEntidad($entidadId);
            $reglasGlpi = $this->cargarReglasDeEntidad(
                $entidadId,
                $rutaEntidad,
                $this->idsAncestros($entidadId, $entidades)
            );

            $categorias = $this->compararCategorias($plantilla, $categoriasGlpi);
            $ansPlantilla = $this->listarAnsPlantilla($plantilla);
            $reglas = $this->compararReglas(
                $plantilla,
                $reglasGlpi,
                $categoriasGlpi,
                $entidadId,
                $rutaEntidad,
                $ansPlantilla
            );

            $filas = array_merge($categorias, $this->aplanarReglas($reglas));
            $resumen = [
                'ok' => count(array_filter($filas, fn ($f) => $f['estado'] === 'ok')),
                'faltan' => count(array_filter($filas, fn ($f) => $f['estado'] === 'falta_glpi')),
                'extra' => count(array_filter($filas, fn ($f) => $f['estado'] === 'extra_glpi')),
                'diferencias' => count(array_filter($filas, fn ($f) => $this->esDiferenciaTexto((string) ($f['estado'] ?? '')))),
            ];
            $resumen['total'] = $resumen['ok'] + $resumen['faltan'] + $resumen['extra'] + $resumen['diferencias'];

            return [
                'entidad' => [
                    'id' => (int) ($entidad['id'] ?? $entidadId),
                    'nombre' => (string) ($entidad['name'] ?? ''),
                    'ruta' => (string) ($entidad['completename'] ?? $entidad['name'] ?? ''),
                ],
                'plantilla' => [
                    'id' => $plantilla->id,
                    'codigo' => $plantilla->codigo,
                    'nombre' => $plantilla->nombre,
                    'prefijo_regla' => $plantilla->prefijo_regla,
                ],
                'resumen' => $resumen,
                'categorias' => $categorias,
                'ans_plantilla' => $ansPlantilla,
                'reglas' => $reglas,
            ];
        } finally {
            $this->activarEntidadRaiz();
            Cache::forget(self::CACHE_ENTIDADES);
        }
    }

    /**
     * Compara una regla de la entidad contra un ANS concreto de la plantilla.
     *
     * @return array<string, mixed>
     */
    public function compararRegla(int $plantillaId, int $entidadId, int $reglaGlpiId, ?string $ansKey): array
    {
        $this->asegurarConfiguracion();
        $this->activarEntidadRaiz();

        try {
            $plantilla = GlpiParamPlantilla::with(['ans', 'categorias'])->find($plantillaId);
            if (! $plantilla) {
                throw new RuntimeException('Plantilla no encontrada.');
            }

            $entidades = $this->cargarEntidades();
            $entidad = collect($entidades)->first(fn ($item) => (int) ($item['id'] ?? -1) === $entidadId);
            if (! $entidad) {
                throw new RuntimeException('La entidad no existe en GLPI o no está visible para la sesión API.');
            }

            $rutaEntidad = (string) ($entidad['completename'] ?? $entidad['name'] ?? '');
            $categoriasGlpi = $this->cargarCategoriasDeEntidad($entidadId);
            $categoriasPorId = [];
            foreach ($categoriasGlpi as $cat) {
                $categoriasPorId[(int) ($cat['id'] ?? 0)] = $cat;
            }

            try {
                $reglaGlpi = $this->glpi->getItem('RuleTicket', $reglaGlpiId, [
                    'expand_dropdowns' => false,
                    'get_hateoas' => false,
                ]);
            } catch (Throwable $e) {
                throw new RuntimeException('No se pudo leer la regla de GLPI.');
            }
            if (! is_array($reglaGlpi) || (int) ($reglaGlpi['id'] ?? 0) <= 0) {
                throw new RuntimeException('La regla de GLPI no existe.');
            }

            $cacheNombres = [];
            $cacheSla = [];
            $detallePorId = [(int) $reglaGlpi['id'] => $reglaGlpi];

            if ($ansKey === null || $ansKey === '') {
                $card = $this->presentarReglaExtraGlpi(
                    $reglaGlpi,
                    $entidadId,
                    $categoriasPorId,
                    $cacheNombres,
                    $cacheSla
                );
                $card['ans_key'] = null;
                $card['glpi_id'] = (int) $reglaGlpi['id'];

                return $card;
            }

            $ans = $plantilla->ans->values()->get((int) $ansKey);
            if (! $ans) {
                throw new RuntimeException('El ANS de la plantilla no existe.');
            }

            $card = $this->evaluarReglaContraAns(
                $plantilla,
                $ans,
                $reglaGlpi,
                $categoriasPorId,
                $entidadId,
                $cacheNombres,
                $cacheSla,
                $detallePorId
            );
            $card['ans_key'] = (string) ((int) $ansKey);
            $card['glpi_id'] = (int) $reglaGlpi['id'];

            return $card;
        } finally {
            $this->activarEntidadRaiz();
        }
    }

    private function asegurarConfiguracion(): void
    {
        if (trim((string) config('glpi.base_url')) === '' || trim((string) config('glpi.user_token')) === '') {
            throw new RuntimeException('GLPI no está configurado (GLPI_BASE_URL / GLPI_USER_TOKEN).');
        }
    }

    /**
     * La sesión API de GLPI es compartida: si queda en una entidad hija, /Entity
     * solo devuelve esa rama. Siempre volvemos a la raíz en recursivo.
     */
    private function activarEntidadRaiz(): void
    {
        try {
            $this->glpi->changeActiveEntities(0, true);
        } catch (Throwable $e) {
            Log::warning('Validador GLPI: no se pudo volver a la entidad raíz: '.$e->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodos
     */
    private function contarNodosArbol(array $nodos): int
    {
        $total = 0;
        foreach ($nodos as $nodo) {
            $total++;
            $hijas = is_array($nodo['hijas'] ?? null) ? $nodo['hijas'] : [];
            if ($hijas !== []) {
                $total += $this->contarNodosArbol($hijas);
            }
        }

        return $total;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cargarEntidades(): array
    {
        $this->activarEntidadRaiz();

        try {
            $filas = $this->glpi->getAllItems('Entity');
            if ($filas !== []) {
                return $filas;
            }
        } catch (Throwable) {
            // Si el usuario API no puede listar Entity, se usa el listado de la sesión.
        }

        $sesion = $this->glpi->normalizeCollection($this->glpi->getMyEntities());
        if ($sesion === []) {
            throw new RuntimeException('No se pudieron leer las entidades de GLPI.');
        }

        return $sesion;
    }

    /**
     * Categorías y reglas de la entidad seleccionada (no un catálogo global recortado).
     *
     * @return list<array<string, mixed>>
     */
    private function cargarCategoriasDeEntidad(int $entidadId): array
    {
        return $this->intentarCatalogo(
            fn () => $this->glpi->searchAllItems(
                'ITILCategory',
                [1, 2, 3, 14, 80],
                [
                    1 => 'completename',
                    2 => 'id',
                    3 => 'is_helpdeskvisible',
                    14 => 'name',
                    80 => 'entity_completename',
                ],
                400,
                [['field' => 80, 'searchtype' => 'equals', 'value' => $entidadId]]
            ),
            'categorías ITIL de la entidad'
        );
    }

    /**
     * Trae todas las reglas de ticket visibles en la entidad (propias y recursivas de padres).
     *
     * @param  list<int>  $ancestros
     * @return list<array<string, mixed>>
     */
    private function cargarReglasDeEntidad(int $entidadId, string $rutaEntidad, array $ancestros): array
    {
        $porId = [];
        $agregar = function (array $filas) use (&$porId): void {
            foreach ($filas as $fila) {
                if (! is_array($fila)) {
                    continue;
                }
                $id = (int) ($fila['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $porId[$id] = array_merge($porId[$id] ?? [], $fila);
            }
        };

        $agregar($this->intentarCatalogo(
            fn () => $this->glpi->searchAllItems(
                'RuleTicket',
                [1, 2, 5, 8, 80, 86],
                [
                    1 => 'name',
                    2 => 'id',
                    5 => 'match',
                    8 => 'is_active',
                    80 => 'entity_completename',
                    86 => 'is_recursive',
                ],
                400,
                [['field' => 80, 'searchtype' => 'equals', 'value' => $entidadId]]
            ),
            'reglas de ticket (búsqueda)'
        ));

        try {
            $this->glpi->changeActiveEntities($entidadId, true);
            $agregar($this->intentarCatalogo(
                fn () => $this->glpi->getAllItems('RuleTicket', [
                    'expand_dropdowns' => false,
                    'get_hateoas' => false,
                ], 200),
                'listado RuleTicket'
            ));
        } catch (Throwable $e) {
            Log::warning('Validador GLPI: no se pudo listar RuleTicket en la entidad activa: '.$e->getMessage());
        } finally {
            $this->activarEntidadRaiz();
        }

        $rutaNorm = $this->normalizarRuta($rutaEntidad);
        $resultado = [];
        foreach ($porId as $regla) {
            if ($this->reglaVisibleEnEntidad($regla, $entidadId, $rutaNorm, $ancestros)) {
                $resultado[] = $regla;
            }
        }

        return array_values($resultado);
    }

    /**
     * @param  array<string, mixed>  $regla
     * @param  list<int>  $ancestros
     */
    private function reglaVisibleEnEntidad(array $regla, int $entidadId, string $rutaNorm, array $ancestros): bool
    {
        $dueno = $this->idEntidadGlpi($regla['entities_id'] ?? null);
        if ($dueno === $entidadId) {
            return true;
        }

        $rutaItem = $this->normalizarRuta((string) ($regla['entity_completename'] ?? ''));
        if ($rutaNorm !== '' && $rutaItem === $rutaNorm) {
            return true;
        }

        return $dueno > 0
            && $this->esVerdaderoGlpi($regla['is_recursive'] ?? 0)
            && in_array($dueno, $ancestros, true);
    }

    /**
     * @param  list<array<string, mixed>>  $entidades
     * @return list<int>
     */
    private function idsAncestros(int $entidadId, array $entidades): array
    {
        $porId = [];
        foreach ($entidades as $fila) {
            $id = (int) ($fila['id'] ?? 0);
            if ($id > 0) {
                $porId[$id] = $fila;
            }
        }

        $ancestros = [];
        $actual = $entidadId;
        for ($i = 0; $i < 40; $i++) {
            $nodo = $porId[$actual] ?? null;
            if (! $nodo) {
                break;
            }
            $parent = (int) ($nodo['entities_id'] ?? -1);
            if ($parent < 0 || $parent === $actual) {
                break;
            }
            $ancestros[] = $parent;
            if ($parent === 0) {
                break;
            }
            $actual = $parent;
        }

        return $ancestros;
    }

    /**
     * @param  callable(): list<array<string, mixed>>  $cargar
     * @return list<array<string, mixed>>
     */
    private function intentarCatalogo(callable $cargar, string $nombre): array
    {
        try {
            return $cargar();
        } catch (Throwable $e) {
            Log::warning("Validador GLPI: no se pudieron leer {$nombre}: ".$e->getMessage());

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    private function armarArbolEntidades(array $filas): array
    {
        $nodos = [];
        foreach ($filas as $fila) {
            $id = (int) ($fila['id'] ?? 0);
            $parentId = (int) ($fila['entities_id'] ?? -1);
            if ($parentId === $id) {
                $parentId = -1;
            }

            $nodos[$id] = [
                'id' => $id,
                'nombre' => (string) ($fila['name'] ?? "Entidad {$id}"),
                'ruta' => (string) ($fila['completename'] ?? $fila['name'] ?? ''),
                'nivel' => (int) ($fila['level'] ?? 1),
                'parent_id' => $parentId < 0 ? null : $parentId,
                'hijas' => [],
            ];
        }

        $raices = [];
        foreach ($nodos as $id => &$nodo) {
            $parentId = $nodo['parent_id'];
            if ($parentId !== null && isset($nodos[$parentId])) {
                $nodos[$parentId]['hijas'][] = &$nodo;
            } else {
                $nodo['parent_id'] = null;
                $raices[] = &$nodo;
            }
        }
        unset($nodo);

        $ordenar = function (array &$lista) use (&$ordenar): void {
            usort($lista, fn ($a, $b) => strcasecmp((string) $a['nombre'], (string) $b['nombre']));
            foreach ($lista as &$item) {
                if ($item['hijas'] !== []) {
                    $ordenar($item['hijas']);
                }
            }
            unset($item);
        };
        $ordenar($raices);

        return $raices;
    }

    /**
     * @param  list<array<string, mixed>>  $categoriasGlpi
     * @return list<array<string, mixed>>
     */
    private function compararCategorias(GlpiParamPlantilla $plantilla, array $categoriasGlpi): array
    {
        $porRuta = [];
        foreach ($categoriasGlpi as $cat) {
            $ruta = $this->normalizarRuta((string) ($cat['completename'] ?? $cat['name'] ?? ''));
            if ($ruta === '') {
                continue;
            }
            $porRuta[$ruta] = $cat;
        }

        $idsConHijas = $plantilla->categorias
            ->pluck('parent_id')
            ->filter()
            ->unique()
            ->all();

        $vistos = [];
        $resultado = [];

        foreach ($plantilla->categorias as $nodo) {
            /** @var GlpiParamPlantillaCategoria $nodo */
            $ruta = $this->normalizarRuta((string) ($nodo->ruta_completa ?: $nodo->nombre ?: $nodo->categoria));
            if ($ruta === '') {
                continue;
            }

            $glpi = $porRuta[$ruta] ?? null;
            $estadoOrtografico = null;
            $rutaGlpiNorm = $ruta;

            if (! $glpi) {
                $parecido = $this->buscarCategoriaPorOrtografia($ruta, $porRuta, $vistos);
                if ($parecido !== null) {
                    $glpi = $parecido['cat'];
                    $rutaGlpiNorm = $parecido['ruta'];
                    $estadoOrtografico = $parecido['estado'];
                }
            }

            $esHoja = ! in_array($nodo->id, $idsConHijas, true);
            $fila = [
                'tipo' => 'categoria',
                'ruta' => $nodo->ruta_completa ?: $ruta,
                'nivel' => (int) $nodo->nivel,
                'es_hoja' => $esHoja,
                'prioridad' => $nodo->prioridad,
                'plantilla' => [
                    'nombre' => $nodo->nombre ?: $nodo->categoria,
                    'prioridad' => $nodo->prioridad,
                ],
                'glpi' => null,
                'detalle' => '',
            ];

            if ($glpi) {
                $vistos[$rutaGlpiNorm] = true;
                $fila['glpi'] = [
                    'id' => (int) ($glpi['id'] ?? 0),
                    'nombre' => (string) ($glpi['name'] ?? ''),
                    'ruta' => (string) ($glpi['completename'] ?? $glpi['name'] ?? ''),
                    'visible_helpdesk' => $this->esVerdaderoGlpi($glpi['is_helpdeskvisible'] ?? 0),
                ];
                if ($estadoOrtografico) {
                    $fila['estado'] = $estadoOrtografico;
                    $fila['detalle'] = $this->detalleOrtografia(
                        $estadoOrtografico,
                        $ruta,
                        $rutaGlpiNorm
                    );
                } else {
                    $fila['estado'] = 'ok';
                    $fila['detalle'] = 'La categoría existe en la entidad seleccionada.';
                }
            } else {
                $fila['estado'] = 'falta_glpi';
                $fila['detalle'] = 'No está en la entidad seleccionada de GLPI.';
            }

            $resultado[] = $fila;
        }

        foreach ($porRuta as $ruta => $glpi) {
            if (isset($vistos[$ruta])) {
                continue;
            }
            if (! $this->esVerdaderoGlpi($glpi['is_helpdeskvisible'] ?? 1)) {
                continue;
            }

            $resultado[] = [
                'tipo' => 'categoria',
                'ruta' => (string) ($glpi['completename'] ?? $glpi['name'] ?? $ruta),
                'nivel' => (int) ($glpi['level'] ?? 1),
                'es_hoja' => true,
                'prioridad' => null,
                'estado' => 'extra_glpi',
                'plantilla' => null,
                'glpi' => [
                    'id' => (int) ($glpi['id'] ?? 0),
                    'nombre' => (string) ($glpi['name'] ?? ''),
                    'ruta' => (string) ($glpi['completename'] ?? $glpi['name'] ?? ''),
                    'visible_helpdesk' => true,
                ],
                'detalle' => 'Está en la entidad seleccionada y no figura en la plantilla.',
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<string, array<string, mixed>>  $porRuta
     * @param  array<string, true>  $vistos
     * @return array{cat: array<string, mixed>, ruta: string, estado: string}|null
     */
    private function buscarCategoriaPorOrtografia(string $rutaPlantilla, array $porRuta, array $vistos): ?array
    {
        $clavePlantilla = $this->claveOrtografica($rutaPlantilla);
        if ($clavePlantilla === '') {
            return null;
        }

        $candidatas = [];
        foreach ($porRuta as $rutaGlpi => $cat) {
            if (isset($vistos[$rutaGlpi])) {
                continue;
            }
            if ($this->claveOrtografica($rutaGlpi) !== $clavePlantilla) {
                continue;
            }
            $estado = $this->clasificarDiferenciaOrtografica($rutaPlantilla, $rutaGlpi);
            if ($estado === null) {
                continue;
            }
            $candidatas[] = [
                'cat' => $cat,
                'ruta' => $rutaGlpi,
                'estado' => $estado,
            ];
        }

        return count($candidatas) === 1 ? $candidatas[0] : null;
    }

    private function clasificarDiferenciaOrtografica(string $plantilla, string $glpi): ?string
    {
        if ($plantilla === $glpi) {
            return null;
        }

        $plantillaTildes = $this->quitarTildes($plantilla);
        $glpiTildes = $this->quitarTildes($glpi);
        $plantillaEspacios = $this->quitarEspacios($plantilla);
        $glpiEspacios = $this->quitarEspacios($glpi);

        if ($this->claveOrtografica($plantilla) !== $this->claveOrtografica($glpi)) {
            return null;
        }

        $porTildes = $plantillaTildes === $glpiTildes;
        $porEspacios = $plantillaEspacios === $glpiEspacios;

        if ($porTildes && ! $porEspacios) {
            return 'tildes';
        }
        if ($porEspacios && ! $porTildes) {
            return 'espacios';
        }

        return 'tildes_espacios';
    }

    private function detalleOrtografia(string $estado, string $rutaPlantilla, string $rutaGlpi): string
    {
        $esperado = $this->ultimoSegmentoRuta($rutaPlantilla) ?: $rutaPlantilla;
        $encontrado = $this->ultimoSegmentoRuta($rutaGlpi) ?: $rutaGlpi;

        return match ($estado) {
            'tildes' => "Es la misma categoría, pero cambia por tildes. Plantilla: «{$esperado}». GLPI: «{$encontrado}».",
            'espacios' => "Es la misma categoría, pero cambia por espacios. Plantilla: «{$esperado}». GLPI: «{$encontrado}».",
            default => "Es la misma categoría, pero cambia por tildes y espacios. Plantilla: «{$esperado}». GLPI: «{$encontrado}».",
        };
    }

    private function esDiferenciaTexto(string $estado): bool
    {
        return in_array($estado, ['diferente', 'tildes', 'espacios', 'tildes_espacios'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $reglasGlpi
     * @param  list<array<string, mixed>>  $categoriasGlpi
     * @param  list<array<string, mixed>>  $ansPlantilla
     * @return list<array<string, mixed>>
     */
    private function compararReglas(
        GlpiParamPlantilla $plantilla,
        array $reglasGlpi,
        array $categoriasGlpi,
        int $entidadId,
        string $rutaEntidad,
        array $ansPlantilla
    ): array {
        $categoriasPorId = [];
        foreach ($categoriasGlpi as $cat) {
            $categoriasPorId[(int) ($cat['id'] ?? 0)] = $cat;
        }

        $nombreEntidad = $this->ultimoSegmentoRuta($rutaEntidad);
        $cacheNombres = [];
        $cacheSla = [];
        $detallePorId = [];
        $ansUsados = [];
        $resultado = [];

        foreach ($reglasGlpi as $regla) {
            $id = (int) ($regla['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $ansKey = $this->ansKeySugerido($plantilla, $regla, $ansPlantilla, $ansUsados, $nombreEntidad);
            if ($ansKey !== null) {
                $ans = $plantilla->ans->values()->get((int) $ansKey);
                $card = $ans
                    ? $this->evaluarReglaContraAns(
                        $plantilla,
                        $ans,
                        $regla,
                        $categoriasPorId,
                        $entidadId,
                        $cacheNombres,
                        $cacheSla,
                        $detallePorId
                    )
                    : $this->presentarReglaExtraGlpi(
                        $regla,
                        $entidadId,
                        $categoriasPorId,
                        $cacheNombres,
                        $cacheSla
                    );
                $card['ans_key'] = $ans ? $ansKey : null;
                if ($ans) {
                    $ansUsados[$ansKey] = true;
                }
            } else {
                $card = $this->presentarReglaExtraGlpi(
                    $regla,
                    $entidadId,
                    $categoriasPorId,
                    $cacheNombres,
                    $cacheSla
                );
                $card['ans_key'] = null;
            }

            $card['glpi_id'] = $id;
            $card['nombre'] = trim((string) ($regla['name'] ?? $card['nombre'] ?? ''));
            $resultado[] = $card;
        }

        return $resultado;
    }

    /**
     * @return list<array{key: string, prioridad: string, nombre: string, label: string}>
     */
    private function listarAnsPlantilla(GlpiParamPlantilla $plantilla): array
    {
        $items = [];
        foreach ($plantilla->ans->values() as $index => $ans) {
            $prioridad = (string) $ans->prioridad;
            $nombre = $ans->nombre_regla ?: GlpiParamPlantilla::nombreRegla(
                $prioridad,
                (string) $plantilla->prefijo_regla
            );
            $labelPrioridad = GlpiParamPlantilla::PRIORIDAD_LABELS[$prioridad] ?? strtoupper($prioridad);
            $items[] = [
                'key' => (string) $index,
                'prioridad' => $prioridad,
                'nombre' => $nombre,
                'label' => trim($labelPrioridad.' — '.$nombre),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $ansPlantilla
     * @param  array<string, true>  $ansUsados
     */
    private function ansKeySugerido(
        GlpiParamPlantilla $plantilla,
        array $regla,
        array $ansPlantilla,
        array $ansUsados,
        string $nombreEntidad
    ): ?string {
        $nombreGlpi = $this->normalizarTexto((string) ($regla['name'] ?? ''));
        if ($nombreGlpi === '') {
            return null;
        }

        foreach ($ansPlantilla as $opcion) {
            $key = (string) ($opcion['key'] ?? '');
            if ($key === '' || isset($ansUsados[$key])) {
                continue;
            }
            $ans = $plantilla->ans->values()->get((int) $key);
            if (! $ans) {
                continue;
            }
            $nombres = array_map(
                fn ($nombre) => $this->normalizarTexto($nombre),
                $this->nombresAns($plantilla, $ans, $nombreEntidad)
            );
            if (in_array($nombreGlpi, $nombres, true)) {
                return $key;
            }
        }

        $prioridad = $this->inferirPrioridadDeNombre((string) ($regla['name'] ?? ''));
        if ($prioridad === '') {
            return null;
        }
        foreach ($ansPlantilla as $opcion) {
            $key = (string) ($opcion['key'] ?? '');
            if ($key === '' || isset($ansUsados[$key])) {
                continue;
            }
            if (($opcion['prioridad'] ?? '') === $prioridad) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $reglaGlpi
     * @param  array<int, array<string, mixed>>  $categoriasPorId
     * @param  array<string, string>  $cacheNombres
     * @param  array<int, array<string, mixed>>  $cacheSla
     * @param  array<int, array<string, mixed>>  $detallePorId
     * @return array<string, mixed>
     */
    private function evaluarReglaContraAns(
        GlpiParamPlantilla $plantilla,
        GlpiParamPlantillaAns $ans,
        array $reglaGlpi,
        array $categoriasPorId,
        int $entidadId,
        array &$cacheNombres,
        array &$cacheSla,
        array &$detallePorId
    ): array {
        $id = (int) ($reglaGlpi['id'] ?? 0);
        $nombreEsperado = $ans->nombre_regla ?: GlpiParamPlantilla::nombreRegla(
            (string) $ans->prioridad,
            (string) $plantilla->prefijo_regla
        );
        $nombreGlpi = trim((string) ($reglaGlpi['name'] ?? $nombreEsperado));

        $detalle = $id > 0 ? $this->detalleReglaGlpi($id, $reglaGlpi) : null;
        $seccionRegla = $this->validarCabeceraRegla($detalle, $nombreEsperado);
        $criterios = $this->validarCriteriosRegla(
            $detalle['criterios'] ?? [],
            $this->hojasDeAns($plantilla, $ans),
            $categoriasPorId
        );
        $acciones = $this->validarAccionesRegla(
            $plantilla,
            $ans,
            $detalle['acciones'] ?? [],
            $cacheNombres
        );
        $ansFilas = $this->validarTiempoSolucion(
            $ans,
            $detalle['acciones'] ?? [],
            $cacheSla
        );

        $estados = array_column(array_merge($seccionRegla, $criterios, $acciones, $ansFilas), 'estado');

        return [
            'tipo' => 'regla',
            'prioridad' => (string) $ans->prioridad,
            'nombre' => $nombreGlpi,
            'estado' => $this->peorEstado($estados),
            'existe' => true,
            'detalle' => 'Comparando la regla de la entidad con el ANS «'.$nombreEsperado.'» de la plantilla.',
            'plantilla' => ['nombre' => $nombreEsperado],
            'glpi' => [
                'id' => $id,
                'nombre' => $nombreGlpi,
                'entities_id' => (int) ($reglaGlpi['entities_id'] ?? $entidadId),
            ],
            'seccion_regla' => $seccionRegla,
            'criterios' => $criterios,
            'acciones' => $acciones,
            'ans' => $ansFilas,
        ];
    }

    /**
     * Regla que está en GLPI y no tiene ANS equivalente en la plantilla.
     * Se listan cabecera, criterios, acciones y tiempo de solución tal como están en GLPI.
     *
     * @param  array<string, mixed>  $regla
     * @param  array<int, array<string, mixed>>  $categoriasPorId
     * @param  array<string, string>  $cacheNombres
     * @param  array<int, array<string, mixed>>  $cacheSla
     * @return array<string, mixed>
     */
    private function presentarReglaExtraGlpi(
        array $regla,
        int $entidadId,
        array $categoriasPorId,
        array &$cacheNombres,
        array &$cacheSla
    ): array {
        $id = (int) $regla['id'];
        $nombreGlpi = trim((string) ($regla['name'] ?? '')) ?: 'Regla #'.$id;
        $detalle = $this->detalleReglaGlpi($id, $regla);
        $prioridad = $this->inferirPrioridadDeNombre($nombreGlpi);

        $seccionRegla = $this->validarCabeceraRegla($detalle, $nombreGlpi);
        foreach ($seccionRegla as &$fila) {
            if (($fila['campo'] ?? '') === 'Nombre') {
                $fila['esperado'] = '—';
                $fila['estado'] = 'extra_glpi';
                $fila['detalle'] = 'No hay un ANS en la plantilla con este nombre.';
            }
        }
        unset($fila);

        $cantidadCats = 0;
        foreach ($detalle['criterios'] ?? [] as $criterio) {
            $campo = (string) ($criterio['criteria'] ?? '');
            if ($this->esCriterioCategoria($campo) && is_numeric($criterio['pattern'] ?? '')) {
                $cantidadCats++;
            }
        }
        $criterios = [
            $this->campo(
                'Categorías en la regla',
                'Elige un ANS para comparar',
                $cantidadCats > 0
                    ? $cantidadCats.' categoría(s) en los criterios'
                    : 'Sin criterios de categoría',
                'extra_glpi',
                'Selecciona el ANS de la plantilla. Se compararán esas categorías con las asociadas a ese ANS.'
            ),
        ];

        $porTipo = [];
        foreach ($detalle['acciones'] ?? [] as $accion) {
            $tipo = $this->tipoAccion((string) ($accion['field'] ?? ''));
            if ($tipo) {
                $porTipo[$tipo] = $accion;
            }
        }

        $grupoGlpi = isset($porTipo['grupo_tecnico'])
            ? $this->resolverNombre('Group', $porTipo['grupo_tecnico']['value'] ?? null, $cacheNombres)
            : '';
        $slaGlpi = isset($porTipo['sla_solucion'])
            ? $this->resolverNombre('SLA', $porTipo['sla_solucion']['value'] ?? null, $cacheNombres)
            : '';
        $prioridadGlpiValor = isset($porTipo['prioridad']) ? (int) ($porTipo['prioridad']['value'] ?? 0) : 0;
        $prioridadGlpi = $prioridadGlpiValor > 0
            ? ($this->prioridadDesdeGlpi($prioridadGlpiValor) ?: (string) $prioridadGlpiValor)
            : '';

        $acciones = [
            $this->campo(
                'Grupo de técnicos',
                '—',
                $grupoGlpi !== '' ? 'Asignar '.$grupoGlpi : '—',
                'extra_glpi',
                $grupoGlpi !== '' ? 'Acción definida en GLPI.' : 'La regla no asigna grupo técnico.'
            ),
            $this->campo(
                'SLA Fecha de solución',
                '—',
                $slaGlpi !== '' ? 'Asignar '.$slaGlpi : '—',
                'extra_glpi',
                $slaGlpi !== '' ? 'Acción definida en GLPI.' : 'La regla no asigna SLA de solución.'
            ),
            $this->campo(
                'Prioridad',
                '—',
                $prioridadGlpi !== '' ? 'Asignar '.$prioridadGlpi : '—',
                'extra_glpi',
                $prioridadGlpi !== '' ? 'Acción definida en GLPI.' : 'La regla no asigna prioridad.'
            ),
        ];

        $slaId = (int) ($porTipo['sla_solucion']['value'] ?? 0);
        $sla = $slaId > 0 ? $this->obtenerSla($slaId, $cacheSla) : null;
        $ansFilas = [
            $this->campo(
                'Tiempo de solución',
                '—',
                $sla ? $this->etiquetaTiempoGlpi($sla) : '—',
                'extra_glpi',
                $sla
                    ? 'ANS leído desde el SLA de la regla en GLPI.'
                    : 'La regla no tiene un SLA de solución para leer el tiempo.'
            ),
        ];

        return [
            'tipo' => 'regla',
            'prioridad' => $prioridad,
            'nombre' => $nombreGlpi,
            'estado' => 'extra_glpi',
            'existe' => true,
            'detalle' => 'Esta regla está en la entidad de GLPI y no corresponde a ningún ANS de la plantilla. Se muestran criterios, acciones y ANS tal como están en GLPI.',
            'plantilla' => null,
            'glpi' => [
                'id' => $id,
                'nombre' => $nombreGlpi,
                'entities_id' => $this->idEntidadGlpi($regla['entities_id'] ?? $detalle['entities_id'] ?? null) ?: $entidadId,
            ],
            'seccion_regla' => $seccionRegla,
            'criterios' => $criterios,
            'acciones' => $acciones,
            'ans' => $ansFilas,
        ];
    }

    private function inferirPrioridadDeNombre(string $nombre): string
    {
        $n = $this->normalizarTexto($nombre);
        foreach ([
            'MUY ALTA' => 'muy_alta',
            'ALTA' => 'alta',
            'MEDIA' => 'media',
            'BAJA' => 'baja',
        ] as $etiqueta => $valor) {
            if (str_starts_with($n, $etiqueta)) {
                return $valor;
            }
        }

        return '';
    }

    /**
     * Busca la regla por nombre y confirma que pertenece a la entidad (entities_id).
     *
     * @param  list<array<string, mixed>>  $reglasGlpi
     * @param  list<string>  $nombres
     * @param  array<int, array<string, mixed>>  $detallePorId
     * @return array<string, mixed>|null
     */
    private function localizarReglaDeEntidad(
        array $reglasGlpi,
        array $nombres,
        int $entidadId,
        string $rutaEntidad,
        array &$detallePorId
    ): ?array {
        $buscadas = array_values(array_unique(array_filter(array_map(
            fn ($nombre) => $this->normalizarTexto($nombre),
            $nombres
        ))));

        $candidatas = [];
        foreach ($reglasGlpi as $regla) {
            $nombre = $this->normalizarTexto((string) ($regla['name'] ?? ''));
            if ($nombre === '' || ! in_array($nombre, $buscadas, true)) {
                continue;
            }
            if ((int) ($regla['id'] ?? 0) <= 0) {
                continue;
            }
            $candidatas[] = $regla;
        }

        $rutaNorm = $this->normalizarRuta($rutaEntidad);
        $exactas = [];
        $otras = [];
        foreach ($candidatas as $regla) {
            $rutaItem = $this->normalizarRuta((string) ($regla['entity_completename'] ?? ''));
            if ($rutaNorm !== '' && $rutaItem === $rutaNorm) {
                $exactas[] = $regla;
            } else {
                $otras[] = $regla;
            }
        }

        foreach ($exactas !== [] ? $exactas : $otras as $regla) {
            $id = (int) $regla['id'];
            if (! isset($detallePorId[$id])) {
                try {
                    $detallePorId[$id] = $this->glpi->getItem('RuleTicket', $id, [
                        'expand_dropdowns' => false,
                        'get_hateoas' => false,
                    ]);
                } catch (Throwable) {
                    $detallePorId[$id] = $regla;
                }
            }

            $item = $detallePorId[$id];
            $dueno = $this->idEntidadGlpi($item['entities_id'] ?? $regla['entities_id'] ?? null);
            $rutaItem = $this->normalizarRuta((string) ($item['entity_completename'] ?? $regla['entity_completename'] ?? ''));
            if ($dueno === $entidadId || ($rutaNorm !== '' && $rutaItem === $rutaNorm) || $dueno < 0) {
                return array_merge($regla, $item);
            }
        }

        return null;
    }

    private function idEntidadGlpi(mixed $valor): int
    {
        if (is_array($valor)) {
            return (int) ($valor['id'] ?? -1);
        }
        if (is_numeric($valor)) {
            return (int) $valor;
        }

        return -1;
    }

    /**
     * Categorías hoja asociadas al ANS (por el ANS elegido al crear la categoría).
     *
     * @return list<array{ruta: string, nombre: string}>
     */
    private function hojasDeAns(GlpiParamPlantilla $plantilla, GlpiParamPlantillaAns $ans): array
    {
        $idsConHijas = $plantilla->categorias
            ->pluck('parent_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $nombresAns = array_values(array_unique(array_filter(array_map(
            fn ($nombre) => $this->normalizarTexto((string) $nombre),
            [
                (string) ($ans->nombre_regla ?: ''),
                (string) ($ans->nombre_sla_solucion ?: ''),
                GlpiParamPlantilla::nombreRegla(
                    (string) $ans->prioridad,
                    (string) ($plantilla->prefijo_regla ?: 'TIC')
                ),
            ]
        ))));

        $prioridad = (string) $ans->prioridad;
        $porNombre = [];
        $porPrioridad = [];
        $usaAsociacion = false;

        foreach ($plantilla->categorias as $nodo) {
            if (in_array((int) $nodo->id, $idsConHijas, true)) {
                continue;
            }

            $fila = [
                'ruta' => (string) ($nodo->ruta_completa ?: $nodo->nombre ?: $nodo->categoria),
                'nombre' => (string) ($nodo->nombre ?: $nodo->categoria),
            ];
            $ansNombre = trim((string) ($nodo->ans_nombre ?? ''));
            if ($ansNombre !== '') {
                $usaAsociacion = true;
                if ($nombresAns !== [] && in_array($this->normalizarTexto($ansNombre), $nombresAns, true)) {
                    $porNombre[] = $fila;
                }
                continue;
            }

            if ((string) ($nodo->prioridad ?: 'baja') === $prioridad) {
                $porPrioridad[] = $fila;
            }
        }

        if ($usaAsociacion) {
            return $porNombre;
        }

        return $porPrioridad;
    }

    /**
     * @param  array<string, mixed>|null  $detalle
     * @return list<array<string, mixed>>
     */
    private function validarCabeceraRegla(?array $detalle, string $nombre): array
    {
        if ($detalle === null) {
            return [
                $this->campo('Activo', 'Sí', '—', 'falta_glpi', 'No se encontró la regla en la entidad.'),
                $this->campo('Motor de reglas', 'O', '—', 'falta_glpi', 'El motor debe ser O (OR).'),
                $this->campo('Usar regla para', 'Agregar / Actualizar', '—', 'falta_glpi', 'Debe aplicarse al agregar y al actualizar.'),
                $this->campo('Nombre', $nombre, '—', 'falta_glpi', 'No se encontró la regla en la entidad.'),
            ];
        }
        $activo = $detalle['is_active'] ?? null;
        $motor = strtoupper(trim(str_replace(['*', '(', ')'], '', (string) ($detalle['match'] ?? ''))));
        $uso = (int) ($detalle['condition'] ?? 0);

        return [
            $this->campo(
                'Activo',
                'Sí',
                $activo === null ? '—' : ((int) $activo === 1 ? 'Sí' : 'No'),
                $activo !== null && (int) $activo === 1 ? 'ok' : 'diferente',
                'La regla debe estar activa.'
            ),
            $this->campo(
                'Motor de reglas',
                'O',
                $motor !== '' ? $motor : '—',
                in_array($motor, ['O', 'OR', 'OU'], true) ? 'ok' : 'diferente',
                'El motor debe ser O (OR).'
            ),
            $this->campo(
                'Usar regla para',
                'Agregar / Actualizar',
                $this->etiquetaUsoRegla($uso),
                $uso === 3 ? 'ok' : 'diferente',
                'Debe aplicarse al agregar y al actualizar.'
            ),
            $this->campo(
                'Nombre',
                $nombre,
                (string) ($detalle['name'] ?? '—'),
                $detalle ? 'ok' : 'falta_glpi',
                $detalle ? 'La regla existe en GLPI.' : 'No se encontró la regla en la entidad.'
            ),
        ];
    }

    /**
     * Cruza las categorías del ANS en la plantilla con las categorías de los criterios de la regla.
     *
     * @param  list<array<string, mixed>>  $criteriosGlpi
     * @param  list<array{ruta: string, nombre: string}>  $hojas
     * @param  array<int, array<string, mixed>>  $categoriasPorId
     * @return list<array<string, mixed>>
     */
    private function validarCriteriosRegla(array $criteriosGlpi, array $hojas, array $categoriasPorId): array
    {
        $categoriasRegla = $this->categoriasDeCriterios($criteriosGlpi, $categoriasPorId);
        $filas = [];
        $usadas = [];

        foreach ($criteriosGlpi as $criterio) {
            $campo = (string) ($criterio['criteria'] ?? '');
            if ($this->esCriterioCategoria($campo)) {
                continue;
            }
            $filas[] = $this->campo(
                'Criterio',
                'Solo criterios de categoría',
                trim($campo.' / '.(string) ($criterio['condition'] ?? '').' / '.(string) ($criterio['pattern'] ?? '')),
                'diferente',
                'La regla de negocio debe usar criterios de categoría (Categoría / es).'
            );
        }

        foreach ($hojas as $hoja) {
            $rutaPlantilla = (string) $hoja['ruta'];
            $match = $this->emparejarCategoriaCriterio($rutaPlantilla, $categoriasRegla, $usadas);
            if ($match !== null) {
                $usadas[$match['key']] = true;
                $estado = (string) $match['estado'];
                $filas[] = $this->campo(
                    'Categoría',
                    $rutaPlantilla,
                    (string) $match['ruta'],
                    $estado,
                    $estado === 'ok'
                        ? 'La categoría del ANS está en los criterios de la regla.'
                        : $this->detalleOrtografia($estado, $rutaPlantilla, (string) $match['ruta'])
                );
                continue;
            }

            $filas[] = $this->campo(
                'Categoría',
                $rutaPlantilla,
                '—',
                'falta_glpi',
                'Esta categoría está asociada al ANS en la plantilla y no aparece en los criterios de la regla.'
            );
        }

        foreach ($categoriasRegla as $key => $catRegla) {
            if (isset($usadas[$key])) {
                continue;
            }
            $filas[] = $this->campo(
                'Categoría',
                '—',
                (string) $catRegla['ruta'],
                'extra_glpi',
                'La regla tiene esta categoría y no está asociada a este ANS en la plantilla.'
            );
        }

        if ($hojas === [] && $categoriasRegla === []) {
            $filas[] = $this->campo(
                'Categoría',
                'Categorías asociadas a este ANS',
                '—',
                'falta_glpi',
                'Este ANS no tiene categorías asociadas en la plantilla.'
            );
        }

        return $filas;
    }

    /**
     * @param  list<array<string, mixed>>  $criteriosGlpi
     * @param  array<int, array<string, mixed>>  $categoriasPorId
     * @return array<string, array{ruta: string, rutaNorm: string}>
     */
    private function categoriasDeCriterios(array $criteriosGlpi, array $categoriasPorId): array
    {
        $categorias = [];
        foreach ($criteriosGlpi as $criterio) {
            $campo = (string) ($criterio['criteria'] ?? '');
            if (! $this->esCriterioCategoria($campo)) {
                continue;
            }

            $pattern = $criterio['pattern'] ?? '';
            if (! is_numeric($pattern)) {
                continue;
            }

            $cat = $categoriasPorId[(int) $pattern] ?? null;
            $ruta = (string) ($cat['completename'] ?? $cat['name'] ?? '');
            if ($ruta === '') {
                $ruta = 'Categoría #'.$pattern;
            }
            $rutaNorm = $this->normalizarRuta($ruta);
            $key = $rutaNorm !== '' ? $rutaNorm : 'id:'.$pattern;
            $categorias[$key] = [
                'ruta' => $ruta,
                'rutaNorm' => $rutaNorm,
            ];
        }

        return $categorias;
    }

    /**
     * @param  array<string, array{ruta: string, rutaNorm: string}>  $categoriasRegla
     * @param  array<string, true>  $usadas
     * @return array{key: string, ruta: string, estado: string}|null
     */
    private function emparejarCategoriaCriterio(string $rutaPlantilla, array $categoriasRegla, array $usadas): ?array
    {
        $norm = $this->normalizarRuta($rutaPlantilla);
        if ($norm === '') {
            return null;
        }

        foreach ($categoriasRegla as $key => $cat) {
            if (isset($usadas[$key])) {
                continue;
            }
            if ($cat['rutaNorm'] === $norm || $this->rutaEsSufijo($norm, $cat['rutaNorm'])) {
                return ['key' => $key, 'ruta' => $cat['ruta'], 'estado' => 'ok'];
            }
        }

        $clave = $this->claveOrtografica($norm);
        $candidatas = [];
        foreach ($categoriasRegla as $key => $cat) {
            if (isset($usadas[$key])) {
                continue;
            }
            $claveGlpi = $this->claveOrtografica($cat['rutaNorm']);
            if ($claveGlpi === $clave || $this->rutaEsSufijo($clave, $claveGlpi)) {
                $estado = $this->clasificarDiferenciaOrtografica($norm, $cat['rutaNorm']) ?? 'tildes';
                $candidatas[] = [
                    'key' => $key,
                    'ruta' => $cat['ruta'],
                    'estado' => $estado,
                ];
            }
        }

        return count($candidatas) === 1 ? $candidatas[0] : null;
    }

    private function rutaEsSufijo(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) {
            return $a !== '' && $a === $b;
        }

        return str_ends_with($a, ' > '.$b) || str_ends_with($b, ' > '.$a);
    }

    /**
     * @param  list<array<string, mixed>>  $accionesGlpi
     * @param  array<string, string>  $cacheNombres
     * @return list<array<string, mixed>>
     */
    private function validarAccionesRegla(
        GlpiParamPlantilla $plantilla,
        GlpiParamPlantillaAns $ans,
        array $accionesGlpi,
        array &$cacheNombres
    ): array {
        $porTipo = [];
        foreach ($accionesGlpi as $accion) {
            $tipo = $this->tipoAccion((string) ($accion['field'] ?? ''));
            if ($tipo) {
                $porTipo[$tipo] = $accion;
            }
        }

        $grupoEsperado = trim((string) ($plantilla->grupo_tecnico ?: ''));
        $grupoGlpi = isset($porTipo['grupo_tecnico'])
            ? $this->resolverNombre('Group', $porTipo['grupo_tecnico']['value'] ?? null, $cacheNombres)
            : '—';
        $grupoOk = isset($porTipo['grupo_tecnico'])
            && ($grupoEsperado === '' || $this->normalizarTexto($grupoGlpi) === $this->normalizarTexto($grupoEsperado));

        $slaEsperado = trim((string) ($ans->nombre_sla_solucion ?: GlpiParamPlantilla::nombreRegla(
            (string) $ans->prioridad,
            (string) $plantilla->prefijo_regla
        )));
        $slaGlpi = isset($porTipo['sla_solucion'])
            ? $this->resolverNombre('SLA', $porTipo['sla_solucion']['value'] ?? null, $cacheNombres)
            : '—';
        $slaOk = isset($porTipo['sla_solucion'])
            && $this->normalizarTexto($slaGlpi) === $this->normalizarTexto($slaEsperado);

        $prioridadLabel = GlpiParamPlantilla::PRIORIDAD_LABELS[$ans->prioridad] ?? strtoupper((string) $ans->prioridad);
        $prioridadGlpiValor = isset($porTipo['prioridad']) ? (int) ($porTipo['prioridad']['value'] ?? 0) : 0;
        $prioridadGlpi = $prioridadGlpiValor > 0
            ? ($this->prioridadDesdeGlpi($prioridadGlpiValor) ?: (string) $prioridadGlpiValor)
            : '—';
        $prioridadOk = $prioridadGlpiValor === $this->prioridadHaciaGlpi((string) $ans->prioridad);

        return [
            $this->campo(
                'Grupo de técnicos',
                $grupoEsperado !== '' ? 'Asignar '.$grupoEsperado : 'Asignar (grupo técnico)',
                isset($porTipo['grupo_tecnico']) ? 'Asignar '.$grupoGlpi : '—',
                $grupoOk ? 'ok' : (isset($porTipo['grupo_tecnico']) ? 'diferente' : 'falta_glpi'),
                'Debe existir la acción Grupo de técnicos.'
            ),
            $this->campo(
                'SLA Fecha de solución',
                'Asignar '.$slaEsperado,
                isset($porTipo['sla_solucion']) ? 'Asignar '.$slaGlpi : '—',
                $slaOk ? 'ok' : (isset($porTipo['sla_solucion']) ? 'diferente' : 'falta_glpi'),
                'El SLA de solución debe coincidir con la prioridad de la regla.'
            ),
            $this->campo(
                'Prioridad',
                'Asignar '.$prioridadLabel,
                isset($porTipo['prioridad']) ? 'Asignar '.$prioridadGlpi : '—',
                $prioridadOk ? 'ok' : (isset($porTipo['prioridad']) ? 'diferente' : 'falta_glpi'),
                'La prioridad de la acción debe ser la misma de la regla y del SLA.'
            ),
        ];
    }

    /**
     * Compara solo el tiempo de solución de la plantilla con el ANS (SLA TTR) de GLPI.
     *
     * @param  list<array<string, mixed>>  $accionesGlpi
     * @param  array<int, array<string, mixed>>  $cacheSla
     * @return list<array<string, mixed>>
     */
    private function validarTiempoSolucion(
        GlpiParamPlantillaAns $ans,
        array $accionesGlpi,
        array &$cacheSla
    ): array {
        $slaId = 0;
        foreach ($accionesGlpi as $accion) {
            if ($this->tipoAccion((string) ($accion['field'] ?? '')) === 'sla_solucion') {
                $slaId = (int) ($accion['value'] ?? 0);
                break;
            }
        }

        $esperado = $this->etiquetaTiempo(
            $ans->tiempo_solucion !== null ? (int) $ans->tiempo_solucion : null,
            (string) ($ans->unidad_solucion ?: 'hora')
        );

        $sla = $slaId > 0 ? $this->obtenerSla($slaId, $cacheSla) : null;
        $glpi = $sla ? $this->etiquetaTiempoGlpi($sla) : '—';

        $minutosPlantilla = $this->aMinutos(
            $ans->tiempo_solucion !== null ? (int) $ans->tiempo_solucion : null,
            (string) ($ans->unidad_solucion ?: 'hora')
        );
        $minutosGlpi = $sla ? $this->aMinutos(
            isset($sla['number_time']) ? (int) $sla['number_time'] : null,
            (string) ($sla['definition_time'] ?? '')
        ) : null;

        if ($minutosPlantilla === null) {
            $estado = 'falta_glpi';
            $detalle = 'La plantilla no define tiempo de solución para esta prioridad.';
        } elseif ($sla === null) {
            $estado = 'falta_glpi';
            $detalle = 'No hay un SLA de solución en la regla para comparar el tiempo.';
        } elseif ($minutosGlpi === null) {
            $estado = 'diferente';
            $detalle = 'El ANS de GLPI no tiene un tiempo de solución legible.';
        } elseif ($minutosPlantilla === $minutosGlpi) {
            $estado = 'ok';
            $detalle = 'El tiempo de solución de la plantilla coincide con el ANS de GLPI.';
        } else {
            $estado = 'diferente';
            $detalle = 'El tiempo de solución de la plantilla no coincide con el ANS de GLPI.';
        }

        return [
            $this->campo('Tiempo de solución', $esperado, $glpi, $estado, $detalle),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cache
     * @return array<string, mixed>|null
     */
    private function obtenerSla(int $id, array &$cache): ?array
    {
        if (! isset($cache[$id])) {
            try {
                $item = $this->glpi->getItem('SLA', $id, [
                    'expand_dropdowns' => false,
                    'get_hateoas' => false,
                ]);
                $cache[$id] = is_array($item) ? $item : [];
            } catch (Throwable) {
                $cache[$id] = [];
            }
        }

        $item = $cache[$id];

        return (int) ($item['id'] ?? 0) > 0 ? $item : null;
    }

    /**
     * @param  array<string, mixed>  $sla
     */
    private function etiquetaTiempoGlpi(array $sla): string
    {
        $nombre = trim((string) ($sla['name'] ?? ''));
        $tiempo = $this->etiquetaTiempo(
            isset($sla['number_time']) ? (int) $sla['number_time'] : null,
            (string) ($sla['definition_time'] ?? '')
        );
        if ($nombre === '' || $tiempo === '—') {
            return $tiempo;
        }

        return $tiempo.' ('.$nombre.')';
    }

    private function etiquetaTiempo(?int $cantidad, string $unidad): string
    {
        if ($cantidad === null || $cantidad <= 0) {
            return '—';
        }

        $etiqueta = match ($this->unidadCanon($unidad)) {
            'minuto' => $cantidad === 1 ? 'minuto' : 'minutos',
            'hora' => $cantidad === 1 ? 'hora' : 'horas',
            'dia' => $cantidad === 1 ? 'día' : 'días',
            'mes' => $cantidad === 1 ? 'mes' : 'meses',
            default => $unidad !== '' ? $unidad : 'horas',
        };

        return $cantidad.' '.$etiqueta;
    }

    private function aMinutos(?int $cantidad, string $unidad): ?int
    {
        if ($cantidad === null || $cantidad <= 0) {
            return null;
        }

        $factor = match ($this->unidadCanon($unidad)) {
            'minuto' => 1,
            'hora' => 60,
            'dia' => 1440,
            'mes' => 43200,
            default => null,
        };

        return $factor === null ? null : $cantidad * $factor;
    }

    private function unidadCanon(string $unidad): string
    {
        $unidad = mb_strtolower(trim($unidad));

        return match (true) {
            in_array($unidad, ['minuto', 'minutos', 'minute', 'minutes'], true) => 'minuto',
            in_array($unidad, ['hora', 'horas', 'hour', 'hours'], true) => 'hora',
            in_array($unidad, ['dia', 'dias', 'día', 'días', 'day', 'days'], true) => 'dia',
            in_array($unidad, ['mes', 'meses', 'month', 'months'], true) => 'mes',
            default => $unidad,
        };
    }

    /**
     * @param  array<string, mixed>  $busqueda
     * @return array<string, mixed>
     */
    private function detalleReglaGlpi(int $id, array $busqueda): array
    {
        $detalle = [
            'id' => $id,
            'name' => $busqueda['name'] ?? '',
            'is_active' => $busqueda['is_active'] ?? null,
            'match' => $busqueda['match'] ?? '',
            'condition' => null,
            'criterios' => [],
            'acciones' => [],
        ];

        if (array_key_exists('condition', $busqueda) || array_key_exists('entities_id', $busqueda)) {
            $detalle['name'] = $busqueda['name'] ?? $detalle['name'];
            $detalle['is_active'] = $busqueda['is_active'] ?? $detalle['is_active'];
            $detalle['match'] = $busqueda['match'] ?? $detalle['match'];
            $detalle['condition'] = $busqueda['condition'] ?? null;
        } else {
            try {
                $item = $this->glpi->getItem('RuleTicket', $id);
                $detalle['name'] = $item['name'] ?? $detalle['name'];
                $detalle['is_active'] = $item['is_active'] ?? $detalle['is_active'];
                $detalle['match'] = $item['match'] ?? $detalle['match'];
                $detalle['condition'] = $item['condition'] ?? null;
            } catch (Throwable $e) {
                Log::warning('Validador GLPI: no se pudo leer RuleTicket '.$id.': '.$e->getMessage());
            }
        }

        $detalle['criterios'] = $this->subItemsRegla($id, 'RuleCriteria');
        $detalle['acciones'] = $this->subItemsRegla($id, 'RuleAction');

        return $detalle;
    }

    /**
     * GET /RuleTicket/{id}/RuleAction no filtra por regla (devuelve el catálogo global).
     * El subrecurso correcto es /Rule/{id}/RuleAction y /Rule/{id}/RuleCriteria.
     *
     * @return list<array<string, mixed>>
     */
    private function subItemsRegla(int $reglaId, string $hijo): array
    {
        try {
            $raw = $this->glpi->get("/Rule/{$reglaId}/{$hijo}", [
                'range' => '0-999',
                'expand_dropdowns' => false,
                'get_hateoas' => false,
            ]);

            $items = $this->glpi->normalizeCollection($raw);

            return array_values(array_filter(
                $items,
                fn ($item) => (int) ($item['rules_id'] ?? 0) === $reglaId
            ));
        } catch (Throwable $e) {
            Log::warning("Validador GLPI: Rule/{$reglaId}/{$hijo}: ".$e->getMessage());

            return [];
        }
    }

    /**
     * @param  array<string, string>  $cache
     */
    private function resolverNombre(string $itemType, mixed $id, array &$cache): string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }
        $key = $itemType.':'.$id;
        if (! isset($cache[$key])) {
            try {
                $item = $this->glpi->getItem($itemType, $id);
                $cache[$key] = (string) ($item['completename'] ?? $item['name'] ?? (string) $id);
            } catch (Throwable) {
                $cache[$key] = (string) $id;
            }
        }

        return $cache[$key];
    }

    private function esCriterioCategoria(string $criteria): bool
    {
        $c = strtolower($criteria);

        return str_contains($c, 'itilcategor') || str_contains($c, 'categor');
    }

    private function tipoAccion(string $field): ?string
    {
        $f = strtolower(ltrim($field, '_'));
        if ($f === 'priority' || $f === 'prioridad') {
            return 'prioridad';
        }
        if ($f === 'slas_id_ttr' || (str_contains($f, 'sla') && str_contains($f, 'ttr'))) {
            return 'sla_solucion';
        }
        if (in_array($f, ['groups_id_assign', 'groups_id_tech', 'groups_id_of_tech'], true)) {
            return 'grupo_tecnico';
        }

        return null;
    }

    private function etiquetaUsoRegla(int $condition): string
    {
        return match ($condition) {
            1 => 'Agregar',
            2 => 'Actualizar',
            3 => 'Agregar / Actualizar',
            0 => '—',
            default => (string) $condition,
        };
    }

    private function prioridadHaciaGlpi(string $prioridad): int
    {
        return match ($prioridad) {
            'baja' => 2,
            'media' => 3,
            'alta' => 4,
            'muy_alta' => 5,
            default => 0,
        };
    }

    private function prioridadDesdeGlpi(int $valor): string
    {
        return match ($valor) {
            2 => 'Baja',
            3 => 'Media',
            4 => 'Alta',
            5 => 'Muy alta',
            1 => 'Muy baja',
            6 => 'Mayor',
            default => (string) $valor,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $reglas
     * @return list<array<string, mixed>>
     */
    private function aplanarReglas(array $reglas): array
    {
        $filas = [];
        foreach ($reglas as $regla) {
            foreach (array_merge($regla['seccion_regla'] ?? [], $regla['criterios'] ?? [], $regla['acciones'] ?? [], $regla['ans'] ?? []) as $campo) {
                $filas[] = $campo;
            }
        }

        return $filas;
    }

    /**
     * @param  list<string>  $estados
     */
    private function peorEstado(array $estados): string
    {
        foreach (['falta_glpi', 'diferente', 'extra_glpi'] as $estado) {
            if (in_array($estado, $estados, true)) {
                return $estado;
            }
        }

        return 'ok';
    }

    private function campo(string $campo, string $esperado, string $glpi, string $estado, string $detalle): array
    {
        return [
            'campo' => $campo,
            'esperado' => $esperado,
            'glpi' => $glpi,
            'estado' => $estado,
            'detalle' => $detalle,
        ];
    }

    /**
     * @return list<string>
     */
    private function nombresAns(GlpiParamPlantilla $plantilla, GlpiParamPlantillaAns $ans, string $nombreEntidad = ''): array
    {
        $label = GlpiParamPlantilla::PRIORIDAD_LABELS[$ans->prioridad] ?? strtoupper((string) $ans->prioridad);
        $prefijo = trim((string) ($plantilla->prefijo_regla ?: 'TIC'));

        return array_values(array_unique(array_filter([
            trim((string) $ans->nombre_regla),
            trim((string) $ans->nombre_sla_solucion),
            trim("{$label} {$prefijo}"),
            GlpiParamPlantilla::nombreRegla((string) $ans->prioridad, $prefijo),
            $nombreEntidad !== '' ? trim("{$label} {$nombreEntidad}") : '',
        ])));
    }

    private function ultimoSegmentoRuta(string $ruta): string
    {
        $ruta = str_replace([' > ', ' / ', '/', '\\'], '>', $ruta);
        $partes = array_values(array_filter(array_map('trim', explode('>', $ruta))));

        return (string) (end($partes) ?: '');
    }

    private function normalizarRuta(string $ruta): string
    {
        $ruta = html_entity_decode($ruta, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ruta = str_replace([' > ', ' / ', '/', '\\'], '>', $ruta);
        $partes = array_values(array_filter(array_map(
            fn ($parte) => $this->normalizarTexto($parte),
            explode('>', $ruta)
        )));

        return implode(' > ', $partes);
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = str_replace(['-', '_', '.', '–', '—'], ' ', $texto);
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        return mb_strtoupper($texto);
    }

    private function quitarTildes(string $texto): string
    {
        if (class_exists(\Normalizer::class)) {
            $descompuesto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
            if (is_string($descompuesto) && $descompuesto !== '') {
                $texto = $descompuesto;
            }
        }

        $texto = preg_replace('/\p{Mn}/u', '', $texto) ?? $texto;

        return strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O',
        ]);
    }

    private function quitarEspacios(string $texto): string
    {
        return preg_replace('/\s+/u', '', $texto) ?? str_replace(' ', '', $texto);
    }

    private function claveOrtografica(string $texto): string
    {
        return $this->quitarEspacios($this->quitarTildes($texto));
    }

    private function esVerdaderoGlpi(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if (is_numeric($valor)) {
            return (int) $valor === 1;
        }

        $texto = mb_strtolower(trim((string) $valor));

        return in_array($texto, ['1', 'yes', 'si', 'sí', 'true'], true);
    }
}
