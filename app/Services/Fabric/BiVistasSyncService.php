<?php

namespace App\Services\Fabric;

use App\Models\BiGrupo;
use App\Models\BiVista;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza las vistas devueltas por la API Python con la tabla bi_vistas.
 *
 * Responsabilidades:
 *   - Detectar vistas nuevas que Fabric devuelve pero no están en bi_vistas
 *   - Crearlas automáticamente como "activo" vinculadas al bi_grupo correcto
 *   - Filtrar vistas inactivas/mantenimiento del catálogo que se entrega al usuario
 *   - Proveer estado de cada vista para el frontend
 *
 * Se ejecuta cada vez que getViewsForUser() recibe respuesta de Python.
 * Usa cache para no hacer queries repetitivas.
 */
class BiVistasSyncService
{
    /**
     * Sincroniza las vistas recibidas de Python con la tabla bi_vistas.
     * Crea registros para vistas nuevas detectadas.
     *
     * @param array $pythonResponse  Respuesta de /api/catalog/views
     * @return array{created: int, total: int}
     */
    public function syncFromCatalogResponse(array $pythonResponse): array
    {
        $schemas = $pythonResponse['schemas'] ?? [];
        $created = 0;
        $total   = 0;

        foreach ($schemas as $schemaBlock) {
            $schemaCode = strtoupper($schemaBlock['schema'] ?? '');
            if ($schemaCode === '') {
                continue;
            }

            // Buscar el bi_grupo correspondiente
            $grupo = $this->resolveGrupo($schemaCode);
            if ($grupo === null) {
                continue;
            }

            $views = $schemaBlock['views'] ?? [];
            foreach ($views as $view) {
                $viewName = $view['view_name'] ?? '';
                if ($viewName === '') {
                    continue;
                }

                $total++;

                // updateOrCreate: si no existe, la crea como activa
                $biVista = BiVista::firstOrCreate(
                    [
                        'id_bi_grupos' => $grupo->id,
                        'nombre'       => $viewName,
                    ],
                    [
                        'descripcion' => $view['qualified_name'] ?? null,
                        'estado'      => BiVista::ESTADO_ACTIVO,
                    ]
                );

                if ($biVista->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        if ($created > 0) {
            // Invalidar cache para que el filtro de departamento se actualice
            Cache::forget('bi_vistas_depto_config');

            Log::info('BiVistasSyncService: vistas nuevas registradas', [
                'created' => $created,
                'total'   => $total,
            ]);
        }

        return ['created' => $created, 'total' => $total];
    }

    /**
     * Filtra las vistas de la respuesta de Python eliminando las inactivas/mantenimiento.
     * Solo pasan las que están en estado "activo" en bi_vistas.
     * Las vistas que NO están en bi_vistas también pasan (primera vez, aún no registradas).
     *
     * @param array $pythonResponse  Respuesta de /api/catalog/views
     * @return array  Respuesta filtrada
     */
    public function filterByEstado(array $pythonResponse): array
    {
        $schemas = $pythonResponse['schemas'] ?? [];
        if (empty($schemas)) {
            return $pythonResponse;
        }

        // Cargar todas las vistas con estado (cache 5 min)
        $estadoIndex = $this->getEstadoIndex();

        foreach ($pythonResponse['schemas'] as &$schemaBlock) {
            $schemaCode = strtolower($schemaBlock['schema'] ?? '');
            $views      = $schemaBlock['views'] ?? [];

            $schemaBlock['views'] = array_values(array_filter(
                $views,
                function ($view) use ($schemaCode, $estadoIndex) {
                    $viewName = strtolower($view['view_name'] ?? '');
                    $key      = "{$schemaCode}.{$viewName}";

                    // Si no está registrada en bi_vistas, dejar pasar
                    // (será registrada automáticamente después)
                    if (!isset($estadoIndex[$key])) {
                        return true;
                    }

                    // Solo pasan las activas
                    return $estadoIndex[$key] === BiVista::ESTADO_ACTIVO;
                }
            ));

            $schemaBlock['view_count'] = count($schemaBlock['views']);
        }
        unset($schemaBlock);

        // Actualizar totales
        $pythonResponse['total_views'] = array_sum(
            array_map(fn ($block) => count($block['views'] ?? []), $pythonResponse['schemas'])
        );

        return $pythonResponse;
    }

    /**
     * Retorna info de estado para una vista específica.
     * Usado cuando se consulta datos de una vista individual.
     *
     * @return array{activa: bool, estado: string, mensaje?: string}
     */
    public function checkVistaEstado(string $schema, string $viewName): array
    {
        $grupo = $this->resolveGrupo(strtoupper($schema));
        if ($grupo === null) {
            return ['activa' => true, 'estado' => 'desconocido'];
        }

        $vista = BiVista::where('id_bi_grupos', $grupo->id)
            ->where('nombre', $viewName)
            ->first();

        if ($vista === null) {
            // No registrada → dejar pasar (se registrará en el próximo sync)
            return ['activa' => true, 'estado' => 'no_registrada'];
        }

        if ($vista->enMantenimiento()) {
            return [
                'activa'  => false,
                'estado'  => BiVista::ESTADO_MANTENIMIENTO,
                'mensaje' => "La vista '{$viewName}' está en mantenimiento. Intente más tarde.",
            ];
        }

        if (!$vista->estaActiva()) {
            return [
                'activa'  => false,
                'estado'  => $vista->estado,
                'mensaje' => "La vista '{$viewName}' no está disponible actualmente.",
            ];
        }

        return ['activa' => true, 'estado' => BiVista::ESTADO_ACTIVO];
    }

    /**
     * Índice de estados: "schema.viewname" => "activo"|"mantenimiento"|"inactivo"
     * Cache 5 minutos para no consultar BD en cada request.
     *
     * @return array<string, string>
     */
    private function getEstadoIndex(): array
    {
        return Cache::remember('bi_vistas_estado_index', 300, function () {
            $index = [];

            BiVista::query()
                ->with('grupo:id,codigo')
                ->get(['id', 'id_bi_grupos', 'nombre', 'estado'])
                ->each(function (BiVista $vista) use (&$index) {
                    $codigo = $vista->grupo?->codigo;
                    if ($codigo === null) {
                        return;
                    }
                    $key = strtolower($codigo) . '.' . strtolower($vista->nombre);
                    $index[$key] = $vista->estado;
                });

            return $index;
        });
    }

    /**
     * Resuelve el BiGrupo por código de esquema.
     * Busca tanto "AA" como "GG-BD-AA".
     */
    private function resolveGrupo(string $schemaCode): ?BiGrupo
    {
        $code = strtoupper(trim($schemaCode));

        return BiGrupo::where('codigo', $code)
            ->orWhere('codigo', 'GG-BD-' . $code)
            ->first();
    }
}
