<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChatBot\ChatBotKnowledgeFeeder;
use Illuminate\Console\Command;

/**
 * Alimenta el catálogo de conocimiento del ChatBot desde Graph-Fabric.
 *
 * Uso:
 *   php artisan chatbot:feed                     (alimentar, máx 100 vistas con columnas)
 *   php artisan chatbot:feed --max=500           (alimentar más vistas de una vez)
 *   php artisan chatbot:feed --all               (todas las que falten, sin límite)
 *
 * Recomendado ejecutar como cron diario para mantener el catálogo actualizado.
 */
class ChatBotFeedKnowledgeCommand extends Command
{
    protected $signature = 'chatbot:feed
        {--max=100 : Máximo de vistas a enriquecer con columnas por ejecución}
        {--all : Procesar todas las vistas pendientes sin límite}';

    protected $description = 'Alimenta el catálogo del ChatBot con columnas reales desde Graph-Fabric';

    public function handle(): int
    {
        $this->info('═══════════════════════════════════════════════');
        $this->info('  ChatBot Knowledge Feeder — Graph-Fabric');
        $this->info('═══════════════════════════════════════════════');
        $this->line('');

        $max = $this->option('all') ? 5000 : (int) $this->option('max');
        $this->line("Máx columnas a sincronizar: {$max}");
        $this->line("URL Graph-Fabric: " . config('fabric.url'));
        $this->line('');

        $feeder = new ChatBotKnowledgeFeeder();

        $bar = $this->output->createProgressBar($max);
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('Iniciando...');
        $bar->start();

        $stats = $feeder->feed($max, function ($current, $total, $viewName) use ($bar) {
            $bar->setMessage($viewName);
            $bar->setProgress($current);
        });

        $bar->finish();
        $this->line('');
        $this->line('');

        $this->info("─────────────────────────────────────────────");
        $this->info("  Resultados:");
        $this->line("  Vistas nuevas registradas: {$stats['synced']}");
        $this->line("  Columnas obtenidas: {$stats['columns_fetched']}");
        if ($stats['errors'] > 0) {
            $this->warn("  Errores: {$stats['errors']}");
        }

        $pendientes = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_views')
            ->where('activo', true)
            ->whereNull('columnas_sync_at')
            ->count();

        $total = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_views')
            ->where('activo', true)
            ->count();

        $this->line("  Total en catálogo: {$total}");
        $this->line("  Pendientes de columnas: {$pendientes}");
        $this->info("─────────────────────────────────────────────");

        if ($pendientes > 0) {
            $this->line('');
            $this->comment("Ejecuta de nuevo para procesar las {$pendientes} restantes.");
        }

        return 0;
    }
}
