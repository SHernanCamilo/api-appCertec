<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ConfigurarSyncCron extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'glpi:setup-cron 
                           {--days=7 : Días para sincronización automática}
                           {--schedule=daily : Frecuencia del cron (daily, weekly, hourly)}';

    /**
     * The console command description.
     */
    protected $description = 'Configura la sincronización automática de activos GLPI mediante cron jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $schedule = $this->option('schedule');

        $this->info("🔧 Configurando sincronización automática de activos GLPI");
        $this->info("📊 Configuración:");
        $this->info("   - Días de sincronización: {$days}");
        $this->info("   - Frecuencia: {$schedule}");

        // Mostrar el comando que se debe agregar al crontab
        $cronCommand = $this->generateCronCommand($days, $schedule);
        
        $this->newLine();
        $this->info("📋 Para configurar la sincronización automática, agrega esta línea a tu crontab:");
        $this->newLine();
        $this->line("# Sincronización automática de activos GLPI");
        $this->line($cronCommand);
        $this->newLine();
        
        $this->info("💡 Comandos útiles:");
        $this->line("   - Editar crontab: crontab -e");
        $this->line("   - Ver crontab actual: crontab -l");
        $this->line("   - Logs de cron: tail -f /var/log/cron");
        $this->newLine();
        
        $this->info("🚀 Comandos disponibles para sincronización:");
        $this->line("   1. Sincronización completa:");
        $this->line("      php artisan glpi:sync-activos --full-sync --force");
        $this->newLine();
        $this->line("   2. Sincronización por días:");
        $this->line("      php artisan glpi:sync-activos --sync-days={$days}");
        $this->newLine();
        $this->line("   3. Activo específico:");
        $this->line("      php artisan glpi:sync-activos --single-asset=ID");
        $this->newLine();

        // Crear un archivo de ejemplo para el cron
        $this->createCronScript($days, $schedule);
        
        return 0;
    }

    private function generateCronCommand($days, $schedule)
    {
        $basePath = base_path();
        $phpPath = PHP_BINARY;
        
        $cronTiming = match($schedule) {
            'hourly' => '0 * * * *',
            'daily' => '0 2 * * *',  // 2:00 AM diario
            'weekly' => '0 2 * * 0', // 2:00 AM domingos
            default => '0 2 * * *'
        };
        
        return "{$cronTiming} cd {$basePath} && {$phpPath} artisan glpi:sync-activos --sync-days={$days} --check-deleted >> storage/logs/cron-sync.log 2>&1";
    }

    private function createCronScript($days, $schedule)
    {
        $scriptPath = base_path('scripts/glpi-sync-cron.sh');
        $scriptDir = dirname($scriptPath);
        
        // Crear directorio si no existe
        if (!is_dir($scriptDir)) {
            mkdir($scriptDir, 0755, true);
        }
        
        $basePath = base_path();
        $phpPath = PHP_BINARY;
        
        $scriptContent = <<<BASH
#!/bin/bash

# Script de sincronización automática de activos GLPI
# Generado automáticamente por ConfigurarSyncCron

# Configuración
BASE_PATH="{$basePath}"
PHP_PATH="{$phpPath}"
SYNC_DAYS="{$days}"
LOG_FILE="\$BASE_PATH/storage/logs/cron-sync.log"

# Función de logging
log_message() {
    echo "[\$(date '+%Y-%m-%d %H:%M:%S')] \$1" >> "\$LOG_FILE"
}

# Cambiar al directorio base
cd "\$BASE_PATH" || exit 1

log_message "=== INICIO SINCRONIZACIÓN AUTOMÁTICA ==="
log_message "Días de sincronización: \$SYNC_DAYS"

# Ejecutar sincronización
"\$PHP_PATH" artisan glpi:sync-activos --sync-days="\$SYNC_DAYS" --check-deleted

EXIT_CODE=\$?

if [ \$EXIT_CODE -eq 0 ]; then
    log_message "✅ Sincronización completada exitosamente"
else
    log_message "❌ Error en sincronización (Exit code: \$EXIT_CODE)"
fi

log_message "=== FIN SINCRONIZACIÓN AUTOMÁTICA ==="
log_message ""

exit \$EXIT_CODE
BASH;

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);
        
        $this->info("📄 Script de cron creado en: {$scriptPath}");
        $this->line("   Puedes usar este script en tu crontab:");
        $this->line("   {$this->generateCronCommandWithScript($schedule, $scriptPath)}");
    }

    private function generateCronCommandWithScript($schedule, $scriptPath)
    {
        $cronTiming = match($schedule) {
            'hourly' => '0 * * * *',
            'daily' => '0 2 * * *',
            'weekly' => '0 2 * * 0',
            default => '0 2 * * *'
        };
        
        return "{$cronTiming} {$scriptPath}";
    }
}