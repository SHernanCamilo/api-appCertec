<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MatrizObsolescenciaCalculatorService;
use App\Models\MatrizObsolescencia\MatzobsActivosC;
use Illuminate\Support\Facades\Log;

class CalcularValoresMatrizObsolescencia extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'matriz:calcular-valores 
                           {--activo= : ID específico de activo para calcular}
                           {--batch=50 : Número de activos a procesar por lote}
                           {--force : Recalcular valores incluso si ya existen}
                           {--solo-nuevos : Solo calcular activos sin valores calculados}';

    /**
     * The console command description.
     */
    protected $description = 'Calcula valores automáticos para la matriz de obsolescencia (edad, valoraciones, puntajes)';

    protected $calculatorService;

    public function __construct(MatrizObsolescenciaCalculatorService $calculatorService)
    {
        parent::__construct();
        $this->calculatorService = $calculatorService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $activoEspecifico = $this->option('activo');
        $batchSize = (int) $this->option('batch');
        $force = $this->option('force');
        $soloNuevos = $this->option('solo-nuevos');

        $this->info("🧮 Iniciando cálculo de valores para matriz de obsolescencia");
        $this->info("📊 Configuración:");
        
        if ($activoEspecifico) {
            $this->info("   - Activo específico: {$activoEspecifico}");
            return $this->calcularActivoEspecifico($activoEspecifico);
        }
        
        $this->info("   - Tamaño de lote: {$batchSize}");
        $this->info("   - Forzar recálculo: " . ($force ? 'Sí' : 'No'));
        $this->info("   - Solo nuevos: " . ($soloNuevos ? 'Sí' : 'No'));

        // Determinar qué activos procesar
        $query = MatzobsActivosC::with('detalles');
        
        if ($soloNuevos && !$force) {
            // Solo activos que no tienen valores calculados
            $query->whereHas('detalles', function($q) {
                $q->where(function($subQ) {
                    $subQ->whereNull('edad')
                         ->orWhereNull('valoracion_edad')
                         ->orWhereNull('valoracion_ram')
                         ->orWhereNull('valoracion_procesador')
                         ->orWhereNull('valoracion_disco');
                });
            });
        }

        $totalActivos = $query->count();
        
        if ($totalActivos === 0) {
            $this->warn("⚠️  No se encontraron activos para procesar");
            return 0;
        }

        $this->info("📈 Total de activos a procesar: {$totalActivos}");

        // Crear barra de progreso
        $progressBar = $this->output->createProgressBar($totalActivos);
        $progressBar->setFormat('verbose');

        // Obtener IDs de activos a procesar
        $activoIds = $query->pluck('id')->toArray();

        try {
            // Procesar en lotes
            $resultado = $this->calculatorService->calcularValoresLote($activoIds, $batchSize);
            
            $progressBar->finish();
            $this->newLine(2);

            // Mostrar resultados
            $this->info("✅ Cálculo completado!");
            $this->info("📊 Estadísticas:");
            $this->info("   - Total procesados: {$resultado['procesados']}");
            $this->info("   - Exitosos: {$resultado['exitosos']}");
            
            if ($resultado['errores'] > 0) {
                $this->warn("   - Errores: {$resultado['errores']}");
            }

            $endTime = now();
            $duration = $endTime->diffInSeconds($startTime);
            $this->info("⏱️  Tiempo total: {$duration} segundos");

            // Log del resultado
            Log::channel('glpi_sync')->info('Cálculo de valores completado', [
                'total_activos' => $totalActivos,
                'resultado' => $resultado,
                'duration_seconds' => $duration,
                'options' => [
                    'batch_size' => $batchSize,
                    'force' => $force,
                    'solo_nuevos' => $soloNuevos
                ]
            ]);

            return 0;

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine();
            
            $this->error("❌ Error durante el cálculo: " . $e->getMessage());
            Log::error('Error en cálculo de valores', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }

    /**
     * Calcular valores para un activo específico
     */
    private function calcularActivoEspecifico($activoId)
    {
        try {
            $this->info("🔍 Calculando valores para activo ID: {$activoId}");
            
            $activo = MatzobsActivosC::with('detalles')->find($activoId);
            
            if (!$activo) {
                $this->error("❌ No se encontró el activo con ID: {$activoId}");
                return 1;
            }
            
            if (!$activo->detalles) {
                $this->error("❌ El activo no tiene detalles técnicos asociados");
                return 1;
            }
            
            $this->info("📋 Activo: {$activo->nombre_equipo}");
            $this->info("🏷️  Serial: " . ($activo->serial ?? 'N/A'));
            
            // Mostrar valores antes del cálculo
            $this->info("\n📊 Valores antes del cálculo:");
            $this->mostrarValoresActivo($activo);
            
            // Realizar cálculo
            $resultado = $this->calculatorService->calcularValoresActivo($activoId);
            
            if ($resultado) {
                // Recargar activo para mostrar valores actualizados
                $activo->refresh();
                $activo->load('detalles');
                
                $this->info("\n✅ Cálculo completado!");
                $this->info("📊 Valores después del cálculo:");
                $this->mostrarValoresActivo($activo);
                
                return 0;
            } else {
                $this->error("❌ Error calculando valores para el activo");
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Mostrar valores actuales de un activo
     */
    private function mostrarValoresActivo($activo)
    {
        $detalle = $activo->detalles;
        
        $this->line("   - Edad: " . ($detalle->edad !== null ? $detalle->edad . " años" : "-"));
        $this->line("   - Vida útil: " . ($detalle->edad_v_util !== null ? $detalle->edad_v_util : "-"));
        $this->line("   - Valoración edad: " . ($detalle->valoracion_edad !== null ? $detalle->valoracion_edad : "-"));
        $this->line("   - Valoración RAM: " . ($detalle->valoracion_ram !== null ? $detalle->valoracion_ram : "-"));
        $this->line("   - Valoración procesador: " . ($detalle->valoracion_procesador !== null ? $detalle->valoracion_procesador : "-"));
        $this->line("   - Valoración disco: " . ($detalle->valoracion_disco !== null ? $detalle->valoracion_disco : "-"));
        $this->line("   - Puntaje general: " . ($activo->puntaje !== null ? $activo->puntaje : "-"));
        
        // Información técnica adicional
        $this->line("\n🔧 Información técnica:");
        $this->line("   - RAM: " . ($detalle->tamano_ram ?? 'N/A') . " GB");
        $this->line("   - Procesador: " . ($detalle->procesador ?? 'N/A'));
        $this->line("   - Disco: " . ($detalle->tamano_disco ?? 'N/A') . " GB (" . ($detalle->tipo_disco ?? 'N/A') . ")");
        $this->line("   - Fecha compra: " . ($detalle->fecha_compra ? $detalle->fecha_compra->format('Y-m-d') : 'N/A'));
    }
}