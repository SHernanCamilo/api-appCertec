<?php

declare(strict_types=1);

namespace App\Services\ChatBot;

use App\Services\Fabric\GraphFabricGatewayService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Alimenta el catálogo de conocimiento del ChatBot desde Graph-Fabric.
 *
 * Este servicio:
 *  1. Consulta todas las vistas disponibles en Fabric (vía Graph-Fabric API)
 *  2. Para cada vista sin columnas, consulta sus columnas reales
 *  3. Genera keywords, categorías y descripciones inteligentes
 *  4. Actualiza chatbot_knowledge_views para que el bot pueda buscar mejor
 *
 * Se ejecuta como Job periódico o manualmente via artisan.
 */
class ChatBotKnowledgeFeeder
{
    private string $baseUrl;
    private string $tokenAdmin;
    private string $apiKey;

    /** Sufijos de sede a ignorar al generar la vista base */
    private const SEDE_SUFFIXES = ['Cmi', 'Eal', 'Nva', 'NvaEal', 'NvaGral', 'Fla', 'Tja', 'Kta', 'Mco', 'Dta', 'Pto'];

    /** Mapeo de keywords en nombres de columnas/vistas → categoría */
    private const CATEGORY_MAP = [
        'censo'       => ['censo', 'cama', 'ocupacion', 'hospitalizad', 'internacion', 'estancia'],
        'egresos'     => ['egreso', 'alta', 'defuncion', 'salida'],
        'urgencias'   => ['urgencia', 'triage', 'emergencia', 'abandono'],
        'facturacion'  => ['factur', 'billing', 'radicacion', 'cuenta', 'soat', 'rips'],
        'inventario'  => ['inventory', 'almacen', 'stock', 'medicamento', 'farmac', 'lote', 'insumo'],
        'cartera'     => ['cartera', 'portfolio', 'glosa', 'recaudo', 'mora', 'cobro'],
        'contabilidad' => ['ledger', 'contab', 'comprobante', 'balance', 'puc'],
        'nomina'      => ['nomina', 'salario', 'incapacidad', 'ausencia', 'turno'],
        'cirugia'     => ['cirugia', 'quirofano', 'programacion', 'cirujano'],
        'consulta'    => ['consulta', 'cita', 'agenda', 'ambulatori'],
        'laboratorio' => ['laboratorio', 'muestra', 'resultado', 'examen'],
        'imagenologia' => ['imagen', 'lectura', 'radiolog', 'ecograf'],
    ];

    public function __construct()
    {
        $this->baseUrl    = rtrim(config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $this->tokenAdmin = config('fabric.token_admin', '');
        $this->apiKey     = config('fabric.api_key', '');
    }

    /**
     * Ejecuta la alimentación completa del catálogo.
     *
     * @param int $maxColumnsSync Máximo de vistas a las que pedir columnas por ejecución
     * @return array{synced: int, columns_fetched: int, errors: int}
     */
    public function feed(int $maxColumnsSync = 100, ?callable $onProgress = null): array
    {
        $stats = ['synced' => 0, 'columns_fetched' => 0, 'errors' => 0];

        // Obtener vistas que no tienen columnas sincronizadas
        $vistasNeedColumns = DB::table('chatbot_knowledge_views')
            ->where('activo', true)
            ->whereNull('columnas_sync_at')
            ->orderByRaw("CASE WHEN columnas_clave IS NOT NULL THEN 1 ELSE 0 END")
            ->limit($maxColumnsSync)
            ->get();

        if ($vistasNeedColumns->isEmpty()) {
            return $stats;
        }

        foreach ($vistasNeedColumns as $index => $vista) {
            if ($onProgress) {
                $onProgress($index + 1, $maxColumnsSync, $vista->view_name);
            }

            $columns = $this->fetchColumns($vista->schema_name, $vista->view_name);

            if ($columns === null || empty($columns)) {
                $stats['errors']++;
                // Marcar como sincronizado con array vacío para no reintentar constantemente
                DB::table('chatbot_knowledge_views')
                    ->where('id', $vista->id)
                    ->update(['columnas_sync_at' => now(), 'updated_at' => now()]);
                continue;
            }

            // Generar keywords y categoría a partir de las columnas
            $columnNames = array_map(fn($c) => $c['name'] ?? '', $columns);
            $keywords = $this->generateKeywords($vista->view_name, $columnNames);
            $categoria = $this->detectCategory($vista->view_name, $columnNames);
            $descripcion = $this->generateRichDescription($vista->schema_name, $vista->view_name, $columnNames);

            DB::table('chatbot_knowledge_views')
                ->where('id', $vista->id)
                ->update([
                    'columnas_clave'   => json_encode($columnNames),
                    'keywords'         => $keywords,
                    'categoria'        => $categoria,
                    'descripcion'      => $descripcion,
                    'columnas_sync_at' => now(),
                    'updated_at'       => now(),
                ]);

            $stats['columns_fetched']++;

            // Pausa para no saturar Graph-Fabric
            usleep(300000); // 300ms
        }

        return $stats;
    }

    /**
     * Consulta el catálogo completo de vistas de Graph-Fabric.
     */
    private function fetchCatalog(): ?array
    {
        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders(['X-API-Key' => $this->apiKey])
                ->post("{$this->baseUrl}/api/catalog/views", [
                    'token' => $this->tokenAdmin,
                ]);

            if (!$response->successful()) {
                Log::warning('ChatBotFeeder: catalog fetch failed', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('ChatBotFeeder: catalog exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Consulta las columnas de una vista específica.
     * Usa el GraphFabricGatewayService que ya maneja auth correctamente.
     */
    private function fetchColumns(string $schema, string $viewName): ?array
    {
        try {
            // Usar usuario ID 17 (admin con acceso a todos los esquemas) para el feeder
            $adminUser = \App\Models\User::find(17);
            if (!$adminUser) {
                $adminUser = \App\Models\User::where('estado', true)->first();
            }

            if (!$adminUser) {
                return null;
            }

            $gateway = new GraphFabricGatewayService();
            $result = $gateway->getViewColumns($adminUser, $schema, $viewName);

            if (!($result['success'] ?? false)) {
                return null;
            }

            return $result['data']['columns'] ?? null;
        } catch (\Throwable $e) {
            Log::debug('ChatBotFeeder: columns exception', [
                'schema' => $schema,
                'view'   => $viewName,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Genera keywords de búsqueda a partir del nombre de vista y columnas.
     */
    private function generateKeywords(string $viewName, array $columnNames): string
    {
        // Del nombre de la vista
        $name = str_replace(['VW_', 'NA_', 'HC_', 'AD_', 'AG_'], '', $viewName);
        $words = preg_split('/[_\s]+/', $name);
        $words = array_map('strtolower', $words);

        // De las columnas (normalizadas)
        foreach ($columnNames as $col) {
            $colWords = preg_split('/[_\s]+/', $col);
            $words = array_merge($words, array_map('strtolower', $colWords));
        }

        // Dedup y limpiar
        $words = array_unique(array_filter($words, fn($w) => strlen($w) > 2));

        return implode(' ', array_slice($words, 0, 50));
    }

    /**
     * Detecta la categoría de una vista basándose en su nombre y columnas.
     */
    private function detectCategory(string $viewName, array $columnNames): ?string
    {
        $searchText = strtolower($viewName . ' ' . implode(' ', $columnNames));

        foreach (self::CATEGORY_MAP as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($searchText, $pattern)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Genera una descripción enriquecida basada en columnas reales.
     */
    private function generateRichDescription(string $schema, string $viewName, array $columnNames): string
    {
        $schemaDescs = [
            'dc' => 'Datos clínicos',
            'hg' => 'Hospitalización',
            'aa' => 'Atención ambulatoria',
            'dt' => 'Apoyo diagnóstico y terapéutico',
            'ug' => 'Urgencias',
            'qx' => 'Quirófanos y cirugía',
            'rf' => 'Referencia y contrarreferencia',
            'pc' => 'Promoción, prevención y salud',
            'in' => 'Inventarios y farmacia',
            'fr' => 'Facturación y radicación',
            'co' => 'Contabilidad',
            'ca' => 'Cartera',
            'df' => 'Datos financieros comunes',
            'no' => 'Nómina y talento humano',
            'gd' => 'Glosas y devoluciones',
            'pt' => 'Pagos y tesorería',
            'cp' => 'Costos y presupuestos',
            'ex' => 'Reportes externos',
            'ra' => 'Reportes administrativos',
            'ct' => 'Contratos',
        ];

        $schemaDesc = $schemaDescs[$schema] ?? strtoupper($schema);

        // Parsear nombre legible de la vista
        $name = str_replace(['VW_', 'NA_', 'HC_', 'AD_', 'AG_', 'Billing_', 'Inventory_', 'Portfolio_', 'Ledger_'], '', $viewName);
        $name = preg_replace('/([A-Z])/', ' $1', $name);
        $name = trim(str_replace('_', ' ', $name));

        // Columnas principales (hasta 8 para la descripción)
        $colsPreview = implode(', ', array_slice($columnNames, 0, 8));
        $totalCols = count($columnNames);

        return "[{$schemaDesc}] {$name}. Columnas ({$totalCols}): {$colsPreview}.";
    }

    /**
     * Genera descripción básica sin columnas.
     */
    private function generateDescription(string $schema, string $viewBase): string
    {
        $schemaDescs = [
            'dc' => 'Datos clínicos', 'hg' => 'Hospitalización', 'aa' => 'Ambulatoria',
            'dt' => 'Diagnóstico', 'ug' => 'Urgencias', 'qx' => 'Cirugía',
            'in' => 'Inventarios', 'fr' => 'Facturación', 'co' => 'Contabilidad',
            'ca' => 'Cartera', 'no' => 'Nómina', 'ra' => 'Administrativos',
        ];

        $schemaDesc = $schemaDescs[$schema] ?? strtoupper($schema);
        $name = str_replace(['VW_', 'NA_', 'HC_'], '', $viewBase);
        $name = trim(str_replace('_', ' ', $name));

        return "[{$schemaDesc}] {$name}";
    }
}
