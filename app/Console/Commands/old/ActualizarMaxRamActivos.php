<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MatrizObsolescencia\MatzobsActivosD;
use Illuminate\Support\Facades\Log;

class ActualizarMaxRamActivos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activos:actualizar-max-ram 
                            {--force : Forzar actualización incluso si max_ram ya tiene valor}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualizar el campo max_ram de todos los activos existentes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando actualización de MaxRAM para activos...');
        
        $force = $this->option('force');
        
        $query = MatzobsActivosD::whereNotNull('tamano_ram')
            ->where('tamano_ram', '>', 0);
        
        if (!$force) {
            // Solo actualizar donde max_ram es NULL o 0
            $query->where(function($q) {
                $q->whereNull('max_ram')
                  ->orWhere('max_ram', 0);
            });
        }
        
        $activos = $query->get();
        $total = $activos->count();
        
        if ($total === 0) {
            $this->info('No hay activos para actualizar.');
            return 0;
        }
        
        $this->info("Se actualizarán {$total} activos...");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $actualizados = 0;
        $errores = 0;
        
        foreach ($activos as $activo) {
            try {
                $maxRam = $activo->tamano_ram * 2;
                $activo->update(['max_ram' => $maxRam]);
                $actualizados++;
                
                Log::info("MaxRAM actualizado", [
                    'activo_id' => $activo->activo_c_id,
                    'tamano_ram' => $activo->tamano_ram,
                    'max_ram' => $maxRam
                ]);
                
            } catch (\Exception $e) {
                $errores++;
                Log::error("Error actualizando MaxRAM", [
                    'activo_id' => $activo->activo_c_id,
                    'error' => $e->getMessage()
                ]);
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        
        $this->info("Actualización completada:");
        $this->info("- Total procesados: {$total}");
        $this->info("- Actualizados exitosamente: {$actualizados}");
        
        if ($errores > 0) {
            $this->error("- Errores: {$errores}");
        }
        
        return 0;
    }
}
