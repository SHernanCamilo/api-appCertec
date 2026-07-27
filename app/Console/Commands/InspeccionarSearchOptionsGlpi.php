<?php

namespace App\Console\Commands;

use App\Services\GLPI\GLPIService;
use Illuminate\Console\Command;

class InspeccionarSearchOptionsGlpi extends Command
{
    protected $signature = 'glpi:inspect-search-options
                           {--itemtype=Computer : Tipo de item a inspeccionar (Computer, Agent, Item_OperatingSystem...)}
                           {--filter= : Mostrar solo opciones cuyo nombre, tabla o campo contenga este texto}
                           {--all : Mostrar todas las opciones, no solo los campos que alimentan la matriz}
                           {--fields= : IDs separados por coma a usar en forcedisplay, en lugar de los campos de la matriz}
                           {--sample= : ID de un activo GLPI para traer sus valores reales}
                           {--rows=0 : Traer los primeros N equipos para ver el formato real de cada campo}
                           {--out= : Ruta de archivo donde guardar el resultado en JSON}';

    protected $description = 'Lista los IDs de search options de GLPI para poder consultar por lotes con /search + forcedisplay';

    /**
     * Campos de Computer que alimentan la matriz de obsolescencia, mapeados a la
     * columna local que resuelven. Confirmados contra listSearchOptions/Computer.
     */
    protected array $targetFields = [
        1 => 'nombre_equipo',
        5 => 'serial',
        6 => 'placa (número de inventario)',
        3 => 'ubicacion',
        70 => 'usuario_glpi',
        4 => 'tipo',
        23 => 'marca',
        40 => 'referencia (modelo)',
        45 => 'sistema_operativo',
        46 => 'versión del SO',
        17 => 'procesador',
        18 => 'numero_procesador (núcleos)',
        110 => 'generacion_ram (designation memoria)',
        111 => 'tamano_ram (MiB)',
        114 => 'tipo_disco',
        115 => 'tamano_disco (MiB)',
        901 => 'agente (tag)',
        19 => 'date_mod (sync incremental)',
        80 => 'entidad',
    ];

    public function __construct(protected GLPIService $glpiService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $itemType = (string) $this->option('itemtype');

        $this->info("🔎 Consultando search options de: {$itemType}");

        try {
            $raw = $this->glpiService->get("/listSearchOptions/{$itemType}");
        } catch (\Exception $e) {
            $this->error("❌ No se pudieron obtener las search options: {$e->getMessage()}");
            return self::FAILURE;
        }

        $options = $this->normalizeOptions($raw);

        if (empty($options)) {
            $this->error("❌ GLPI no devolvió opciones para {$itemType}. Verifica que el itemtype exista.");
            return self::FAILURE;
        }

        $this->info("✅ Total de opciones disponibles: " . count($options));

        $shown = $this->filterOptions($options);

        if (empty($shown)) {
            $this->warn('⚠️  Ningún campo coincidió con el filtro. Usa --all para ver todo.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['ID', 'Nombre', 'Tabla', 'Campo', 'Tipo', 'Alimenta'],
            array_map(fn($o) => [
                $o['id'],
                $o['name'],
                $o['table'],
                $o['field'],
                $o['datatype'],
                $this->targetFields[$o['id']] ?? '-',
            ], $shown)
        );

        $forcedisplay = $this->resolveForcedisplay($options, $shown);

        $this->newLine();
        $this->info('📋 IDs para forcedisplay: ' . implode(',', $forcedisplay));

        $sample = null;
        if ($assetId = $this->option('sample')) {
            $sample = $this->fetchSampleById($itemType, (int) $assetId, $forcedisplay, $options);
        } elseif ($rows = (int) $this->option('rows')) {
            $sample = $this->fetchSampleRows($itemType, $rows, $forcedisplay, $options);
        }

        if ($path = $this->option('out')) {
            $this->writeOutput($path, $itemType, $options, $shown, $sample);
        }

        $this->newLine();
        $this->line('Siguiente paso sugerido:');
        $this->line('  php artisan glpi:inspect-search-options --rows=3');
        $this->line('  php artisan glpi:inspect-search-options --sample=<id_glpi_de_un_equipo>');
        $this->line('  php artisan glpi:inspect-search-options --filter=memoria');

        return self::SUCCESS;
    }

    /**
     * GLPI devuelve un objeto mezclando encabezados de sección (valores string)
     * con las opciones reales (valores array indexados por el ID de búsqueda).
     */
    protected function normalizeOptions(array $raw): array
    {
        $options = [];
        $section = '-';

        foreach ($raw as $key => $value) {
            if (!is_array($value)) {
                $section = (string) $value;
                continue;
            }

            if (!is_numeric($key)) {
                continue;
            }

            $options[(int) $key] = [
                'id' => (int) $key,
                'name' => $value['name'] ?? '-',
                'section' => $section,
                'table' => $value['table'] ?? '-',
                'field' => $value['field'] ?? '-',
                'datatype' => $value['datatype'] ?? '-',
                'uid' => $value['uid'] ?? null,
            ];
        }

        ksort($options);

        return $options;
    }

    protected function filterOptions(array $options): array
    {
        if ($this->option('all')) {
            return array_values($options);
        }

        if ($filter = $this->option('filter')) {
            $needle = mb_strtolower((string) $filter);

            return array_values(array_filter($options, function ($option) use ($needle) {
                $haystack = mb_strtolower(
                    $option['name'] . ' ' . $option['table'] . ' ' . $option['field'] . ' ' . ($option['uid'] ?? '')
                );

                return str_contains($haystack, $needle);
            }));
        }

        $shown = [];
        foreach (array_keys($this->targetFields) as $id) {
            if (isset($options[$id])) {
                $shown[] = $options[$id];
                continue;
            }

            $this->warn("⚠️  El campo {$id} ({$this->targetFields[$id]}) no existe en este GLPI.");
        }

        return $shown;
    }

    protected function resolveForcedisplay(array $options, array $shown): array
    {
        if ($fields = $this->option('fields')) {
            return array_values(array_filter(array_map('intval', explode(',', (string) $fields))));
        }

        if ($this->option('all') || $this->option('filter')) {
            return array_values(array_intersect(array_keys($options), array_keys($this->targetFields)));
        }

        return array_column($shown, 'id');
    }

    /**
     * GLPI prohíbe filtrar por el campo 2 (id) cuando se usa forcedisplay,
     * así que se resuelve el nombre del activo y se busca por él.
     */
    protected function fetchSampleById(string $itemType, int $assetId, array $fieldIds, array $options): ?array
    {
        $this->newLine();
        $this->info("🧪 Resolviendo el nombre del activo {$assetId}");

        try {
            $item = $this->glpiService->getItem($itemType, $assetId, ['expand_dropdowns' => false]);
        } catch (\Exception $e) {
            $this->error("❌ No se pudo leer el activo {$assetId}: {$e->getMessage()}");
            return null;
        }

        $name = $item['name'] ?? null;

        if (!$name) {
            $this->error("❌ El activo {$assetId} no tiene nombre, no se puede filtrar por él.");
            return null;
        }

        $this->line("   Nombre: {$name}");

        // El campo 1 es itemlink: 'equals' no devuelve nada, hay que usar 'contains'.
        return $this->runSampleSearch($itemType, $fieldIds, $options, [
            [
                'field' => 1,
                'searchtype' => 'contains',
                'value' => $name,
            ],
        ], 1);
    }

    protected function fetchSampleRows(string $itemType, int $rows, array $fieldIds, array $options): ?array
    {
        $this->newLine();
        $this->info("🧪 Trayendo los primeros {$rows} equipos para ver el formato real de cada campo");

        return $this->runSampleSearch($itemType, $fieldIds, $options, [], $rows);
    }

    /**
     * Muestra qué valor entrega cada ID. Es la única forma de confirmar formato
     * y comportamiento multivalor (equipos con 2 módulos de RAM o 2 discos).
     */
    protected function runSampleSearch(string $itemType, array $fieldIds, array $options, array $criteria, int $rows): ?array
    {
        // GLPI devuelve vacío con range 0-0 cuando hay criteria, así que se pide
        // un mínimo de dos filas y se recorta después.
        $params = [
            'forcedisplay' => array_values($fieldIds),
            'range' => '0-' . max(1, $rows - 1),
        ];

        if (!empty($criteria)) {
            $params['criteria'] = $criteria;
        }

        try {
            $response = $this->glpiService->get("/search/{$itemType}", $params);
        } catch (\Exception $e) {
            $this->error("❌ Error en la búsqueda de prueba: {$e->getMessage()}");
            return null;
        }

        $data = array_slice($response['data'] ?? [], 0, $rows);

        if (empty($data)) {
            $this->warn('⚠️  La búsqueda no devolvió filas.');
            return null;
        }

        $this->line('   totalcount: ' . ($response['totalcount'] ?? '?') . ' | filas recibidas: ' . count($data));

        foreach ($data as $index => $row) {
            $tableRows = [];

            foreach ($fieldIds as $fieldId) {
                $value = $row[$fieldId] ?? $row[(string) $fieldId] ?? null;

                $tableRows[] = [
                    $fieldId,
                    $options[$fieldId]['name'] ?? '(desconocido)',
                    $this->targetFields[$fieldId] ?? '-',
                    is_array($value) ? 'array(' . count($value) . ')' : gettype($value),
                    $this->formatValue($value),
                ];
            }

            $this->newLine();
            $this->line("── Fila #{$index} " . str_repeat('─', 40));
            $this->table(['ID', 'Nombre', 'Alimenta', 'Tipo PHP', 'Valor'], $tableRows);
        }

        return $data;
    }

    protected function formatValue($value): string
    {
        if (is_array($value)) {
            return mb_strimwidth(json_encode($value, JSON_UNESCAPED_UNICODE), 0, 120, '...');
        }

        if (is_null($value)) {
            return '(null)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_strimwidth((string) $value, 0, 120, '...');
    }

    protected function writeOutput(string $path, string $itemType, array $options, array $shown, ?array $sample): void
    {
        $payload = json_encode([
            'itemtype' => $itemType,
            'generated_at' => now()->toDateTimeString(),
            'total_options' => count($options),
            'candidates' => $shown,
            'forcedisplay' => array_column($shown, 'id'),
            'sample' => $sample,
            'all_options' => $options,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($path, $payload) === false) {
            $this->error("❌ No se pudo escribir en {$path}");
            return;
        }

        $this->info("💾 Resultado guardado en {$path}");
    }
}
