<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use Illuminate\Support\Facades\Log;

class CierreAutomaticoJob extends BaseScheduledJob
{
    /**
     * Timeout en segundos (30 minutos)
     */
    public $timeout = 1800;

    /**
     * Ejecutar el cierre automático de inventario
     */
    protected function execute(ScheduledTask $task)
    {
        $empresaId = $this->parameters['empresa_id'] ?? null;
        
        // Calcular periodo automáticamente si no se proporciona
        // Esto permite que las tareas recurrentes funcionen sin actualizar el periodo manualmente
        $periodo = $this->parameters['periodo'] ?? now()->format('Y-m');
        
        Log::channel('cron')->info("Periodo calculado", [
            'periodo_parametro' => $this->parameters['periodo'] ?? 'no especificado',
            'periodo_usado' => $periodo,
        ]);

        Log::channel('cron')->info("Iniciando cierre automático de inventario", [
            'task_id' => $task->id,
            'empresa_id' => $empresaId ?? 'todas',
            'periodo' => $periodo,
        ]);

        try {
            // Verificar que no haya un cierre en proceso
            $enProceso = \App\Models\MatrizObsolescencia\MatzobsCierre::where('estado', 'procesando')->exists();
            if ($enProceso) {
                throw new \Exception('Ya hay un cierre en proceso. No se puede iniciar otro cierre automático.');
            }

            // Determinar el nombre del cierre según si es para una empresa específica o todas
            if ($empresaId) {
                // Cierre para una empresa específica
                $empresa = \App\Models\Empresa::find($empresaId);
                if (!$empresa) {
                    throw new \Exception("Empresa con ID {$empresaId} no encontrada");
                }
                
                $nombreCierre = "Cierre Automático - {$empresa->nombre} - {$periodo}";
                $descripcionCierre = "Cierre automático programado para la empresa {$empresa->nombre}";
                
                Log::channel('cron')->info("Cierre para empresa específica", [
                    'empresa_id' => $empresaId,
                    'empresa_nombre' => $empresa->nombre,
                ]);
            } else {
                // Cierre para todas las empresas
                $nombreCierre = "Cierre Automático - Todas las Empresas - {$periodo}";
                $descripcionCierre = "Cierre automático programado para todas las empresas del sistema";
                
                Log::channel('cron')->info("Cierre para todas las empresas");
            }

            // Crear el registro de cierre en estado pendiente
            $cierre = \App\Models\MatrizObsolescencia\MatzobsCierre::create([
                'nombre'         => $nombreCierre,
                'periodo'        => $periodo,
                'descripcion'    => $descripcionCierre,
                'estado'         => 'pendiente',
                'creado_por'     => null, // Sistema automático
                'nombre_creador' => 'Sistema Automático',
            ]);

            Log::channel('cron')->info("Registro de cierre creado", [
                'cierre_id' => $cierre->id,
                'nombre' => $cierre->nombre,
            ]);

            // Ejecutar el cierre usando el servicio directamente
            // Con QUEUE_CONNECTION=sync, esto se ejecuta inmediatamente
            $service = app(\App\Services\CierreInventarioService::class);
            $service->ejecutar($cierre->id);
            
            Log::channel('cron')->info("Servicio de cierre ejecutado");
            
            // Recargar el cierre para obtener el estado actualizado
            $cierre->refresh();
            
            Log::channel('cron')->info("Cierre automático de inventario completado", [
                'cierre_id' => $cierre->id,
                'estado' => $cierre->estado,
                'total_activos' => $cierre->total_activos ?? 0,
            ]);
            
            return "Cierre de inventario '{$cierre->nombre}' completado. Estado: {$cierre->estado}. Activos procesados: {$cierre->total_activos}";
            
        } catch (\Exception $e) {
            Log::channel('cron')->error("Error en cierre automático de inventario", [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
