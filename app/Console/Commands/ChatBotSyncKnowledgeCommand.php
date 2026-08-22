<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza el catálogo de conocimiento del ChatBot desde bi_vistas.
 *
 * Este comando:
 *  1. Lee todas las vistas activas de bi_vistas + bi_grupos
 *  2. Las agrupa por "vista base" (eliminando sufijos de sede)
 *  3. Inserta/actualiza en chatbot_knowledge_views
 *  4. Permite importar un JSON descriptivo del equipo de Python
 *
 * Uso:
 *   php artisan chatbot:sync-knowledge                    (auto-sync desde bi_vistas)
 *   php artisan chatbot:sync-knowledge --from-json=path   (importar JSON descriptivo)
 *   php artisan chatbot:sync-knowledge --export-format    (genera template JSON para Python)
 */
class ChatBotSyncKnowledgeCommand extends Command
{
    protected $signature = 'chatbot:sync-knowledge
        {--from-json= : Ruta al JSON con descripciones de vistas del equipo de Python}
        {--export-format : Genera un template JSON para que el equipo de Python llene las descripciones}
        {--schema= : Sincronizar solo un esquema específico}';

    protected $description = 'Sincroniza el catálogo de conocimiento del ChatBot desde bi_vistas o JSON externo';

    /** Sufijos de sede conocidos */
    private const SEDE_SUFFIXES = ['Cmi', 'Eal', 'Nva', 'NvaEal', 'NvaGral', 'Fla', 'Tja', 'Kta', 'Mco', 'Dta', 'Pto'];

    /** Mapeo sede → nombre legible */
    private const SEDE_NAMES = [
        'Cmi' => 'Neiva (Clínica Medilaser)',
        'Eal' => 'Neiva (Clínica Emcosalud)',
        'Nva' => 'Neiva',
        'NvaEal' => 'Neiva (consolidado)',
        'NvaGral' => 'Neiva (general)',
        'Fla' => 'Florencia',
        'Tja' => 'Tunja',
        'Kta' => 'Neiva (Kta)',
        'Mco' => 'Mocoa',
        'Dta' => 'Duitama',
        'Pto' => 'Pitalito',
    ];

    /** Tipos de grupo */
    private const TIPOS = [
        1 => 'Asistencial',
        2 => 'Financiero',
        3 => 'Administrativo',
    ];

    public function handle(): int
    {
        if ($this->option('export-format')) {
            return $this->exportTemplate();
        }

        if ($this->option('from-json')) {
            return $this->importFromJson($this->option('from-json'));
        }

        return $this->syncFromBiVistas();
    }

    /**
     * Genera el template JSON para que el equipo de Python llene descripciones.
     */
    private function exportTemplate(): int
    {
        $this->info('Generando template JSON para el equipo de Python...');

        $vistas = $this->getGroupedViews();

        $template = [];
        foreach ($vistas as $key => $info) {
            $template[] = [
                'schema'             => $info['schema'],
                'view_base'          => $info['base'],
                'tipo'               => self::TIPOS[$info['tipo']] ?? 'Desconocido',
                'sedes_disponibles'  => $info['sedes'],
                'descripcion'        => '', // ← El equipo Python llena esto
                'columnas_clave'     => [], // ← El equipo Python llena esto
                'ejemplo_preguntas'  => [], // ← El equipo Python llena esto
                'notas_negocio'      => '', // ← El equipo Python llena esto
            ];
        }

        $path = storage_path('app/chatbot_knowledge_template.json');
        file_put_contents($path, json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Template generado: {$path}");
        $this->info("Total vistas base: " . count($template));
        $this->line('');
        $this->line('Envía este archivo al equipo de Python para que llene:');
        $this->line('  - descripcion: Qué contiene la vista en lenguaje natural');
        $this->line('  - columnas_clave: Array de columnas principales');
        $this->line('  - ejemplo_preguntas: Preguntas que esta vista responde');
        $this->line('  - notas_negocio: Reglas de negocio o aclaraciones');
        $this->line('');
        $this->line('Luego importa con: php artisan chatbot:sync-knowledge --from-json=ruta/al/archivo.json');

        return 0;
    }

    /**
     * Importa descripciones desde un JSON llenado por el equipo de Python.
     */
    private function importFromJson(string $path): int
    {
        if (!file_exists($path)) {
            $this->error("Archivo no encontrado: {$path}");
            return 1;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            $this->error('El archivo no contiene JSON válido.');
            return 1;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($data as $item) {
            $schema = strtolower($item['schema'] ?? '');
            $viewBase = $item['view_base'] ?? '';
            $desc = $item['descripcion'] ?? '';

            if ($schema === '' || $viewBase === '') {
                $skipped++;
                continue;
            }

            // Si no tiene descripción, generar una básica
            if ($desc === '') {
                $desc = $this->generateBasicDescription($schema, $viewBase, $item);
            }

            DB::table('chatbot_knowledge_views')->updateOrInsert(
                ['schema_name' => $schema, 'view_name' => $viewBase],
                [
                    'descripcion'       => $desc,
                    'columnas_clave'    => json_encode($item['columnas_clave'] ?? []),
                    'ejemplo_preguntas' => json_encode($item['ejemplo_preguntas'] ?? []),
                    'filtros_sugeridos' => json_encode($item['filtros_sugeridos'] ?? []),
                    'notas_negocio'     => $item['notas_negocio'] ?? null,
                    'grupo_requerido'   => 'GG-BD-' . strtoupper($schema),
                    'activo'            => true,
                    'updated_at'        => now(),
                    'created_at'        => now(),
                ]
            );
            $imported++;
        }

        $this->info("Importadas: {$imported} | Saltadas: {$skipped}");
        return 0;
    }

    /**
     * Auto-sincroniza desde bi_vistas (genera descripciones básicas automáticas).
     */
    private function syncFromBiVistas(): int
    {
        $this->info('Sincronizando catálogo desde bi_vistas...');

        $schemaFilter = $this->option('schema');
        $vistas = $this->getGroupedViews($schemaFilter);

        $created = 0;
        $updated = 0;

        foreach ($vistas as $key => $info) {
            $exists = DB::table('chatbot_knowledge_views')
                ->where('schema_name', $info['schema'])
                ->where('view_name', $info['base'])
                ->first();

            if ($exists && !empty($exists->descripcion) && $exists->descripcion !== '') {
                // Ya tiene descripción manual → no sobrescribir
                $updated++;
                continue;
            }

            $desc = $this->generateBasicDescription($info['schema'], $info['base'], $info);

            DB::table('chatbot_knowledge_views')->updateOrInsert(
                ['schema_name' => $info['schema'], 'view_name' => $info['base']],
                [
                    'descripcion'    => $desc,
                    'columnas_clave' => null,
                    'grupo_requerido' => 'GG-BD-' . strtoupper($info['schema']),
                    'activo'         => true,
                    'updated_at'     => now(),
                    'created_at'     => DB::raw('IFNULL(created_at, NOW())'),
                ]
            );
            $created++;
        }

        $this->info("Creadas: {$created} | Ya existían con desc: {$updated}");
        $this->info("Total en catálogo: " . DB::table('chatbot_knowledge_views')->where('activo', true)->count());

        return 0;
    }

    /**
     * Agrupa las vistas por "base" eliminando sufijos de sede.
     */
    private function getGroupedViews(?string $schemaFilter = null): array
    {
        $query = DB::table('bi_vistas')
            ->join('bi_grupos', 'bi_vistas.id_bi_grupos', '=', 'bi_grupos.id')
            ->select('bi_grupos.codigo as schema', 'bi_grupos.tipo', 'bi_vistas.nombre')
            ->where('bi_vistas.estado', 'activo');

        if ($schemaFilter) {
            $query->where('bi_grupos.codigo', strtoupper($schemaFilter));
        }

        $vistas = $query->get();

        $regex = '/(_(' . implode('|', self::SEDE_SUFFIXES) . '))$/';
        $basesMap = [];

        foreach ($vistas as $v) {
            $base = preg_replace($regex, '', $v->nombre);
            $key = strtolower($v->schema) . '.' . $base;

            if (!isset($basesMap[$key])) {
                $basesMap[$key] = [
                    'schema' => strtolower($v->schema),
                    'tipo'   => $v->tipo,
                    'base'   => $base,
                    'sedes'  => [],
                ];
            }

            if (preg_match($regex, $v->nombre, $m)) {
                $basesMap[$key]['sedes'][] = $m[2];
            } else {
                $basesMap[$key]['sedes'][] = 'NAL';
            }
        }

        // Deduplicar sedes
        foreach ($basesMap as &$v) {
            $v['sedes'] = array_values(array_unique($v['sedes']));
        }

        return $basesMap;
    }

    /**
     * Genera una descripción básica basada en el nombre de la vista.
     */
    private function generateBasicDescription(string $schema, string $viewBase, array $info = []): string
    {
        $tipo = self::TIPOS[$info['tipo'] ?? 0] ?? '';
        $sedes = $info['sedes'] ?? [];
        $sedesText = in_array('NAL', $sedes) ? 'Nacional (todas las sedes)' : implode(', ', array_map(fn($s) => self::SEDE_NAMES[$s] ?? $s, $sedes));

        // Parsear el nombre para generar descripción
        $name = str_replace(['VW_', 'NA_', 'HC_'], '', $viewBase);
        $name = preg_replace('/([A-Z])/', ' $1', $name);
        $name = trim(str_replace('_', ' ', $name));

        $schemaDescs = [
            'dc' => 'Datos clínicos',
            'hg' => 'Hospitalización',
            'aa' => 'Atención ambulatoria',
            'dt' => 'Apoyo diagnóstico',
            'ug' => 'Urgencias',
            'qx' => 'Quirófanos',
            'rf' => 'Referencia',
            'pc' => 'Promoción y prevención',
            'in' => 'Inventarios',
            'fr' => 'Facturación',
            'co' => 'Contabilidad',
            'ca' => 'Cartera',
            'df' => 'Datos financieros',
            'no' => 'Nómina',
            'gd' => 'Glosas',
            'pt' => 'Tesorería',
            'cp' => 'Costos',
            'ex' => 'Externos',
            'ra' => 'Reportes administrativos',
            'ct' => 'Contratos',
        ];

        $schemaDesc = $schemaDescs[$schema] ?? strtoupper($schema);

        return "[{$tipo}] {$schemaDesc} — {$name}. Disponible para: {$sedesText}.";
    }
}
