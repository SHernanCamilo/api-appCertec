<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateInventoryCounters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:migrate-counters';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate sequence counters from JadeInventory secuencias_numericas to config_sec_detalles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando migración de contadores desde secuencias_numericas...');

        if (!Schema::hasTable('secuencias_numericas')) {
            $this->error('La tabla secuencias_numericas no existe. No se puede migrar.');
            return 1;
        }

        $secuenciasViejas = DB::table('secuencias_numericas')->get();
        if ($secuenciasViejas->isEmpty()) {
            $this->warn('La tabla secuencias_numericas está vacía.');
            return 0;
        }

        $migrados = 0;

        foreach ($secuenciasViejas as $old) {
            // Documentos tipo INVENTARIO en Jade: 
            // - PDI = Pedido Interno
            // - OCI = Orden de Compra Interna
            // - RTI = Recepción Técnica Interna
            
            $modulo = null;
            if ($old->tipo_documento === 'PDI' || stripos($old->tipo_documento, 'PEDIDO') !== false) {
                $modulo = 'PEDIDO';
            } elseif ($old->tipo_documento === 'OCI' || stripos($old->tipo_documento, 'COMPRA') !== false) {
                $modulo = 'ORDEN_COMPRA';
            } elseif ($old->tipo_documento === 'RTI' || stripos($old->tipo_documento, 'RECEPCION') !== false) {
                $modulo = 'RECEPCION';
            }

            if (!$modulo) {
                $this->line("Saltando {$old->tipo_documento}, no es un documento de inventario reconocido.");
                continue;
            }

            $sucursalId = $old->sucursal_id;
            $siguiente = $old->ultimo_numero + 1; // Si iba en el 176, el siguiente es 177

            // Buscar en config_sec_detalles
            $detalle = DB::table('config_sec_detalles as d')
                ->join('config_sec_secuencias as s', 'd.secuencia_id', '=', 's.id')
                ->join('seg_modulos as m', 's.proceso_id', '=', 'm.id')
                ->where('m.codigo', $modulo)
                ->where('d.sucursal_id', $sucursalId)
                ->select('d.id', 'd.siguiente_numero')
                ->first();

            if ($detalle) {
                if ($detalle->siguiente_numero < $siguiente) {
                    DB::table('config_sec_detalles')
                        ->where('id', $detalle->id)
                        ->update(['siguiente_numero' => $siguiente]);
                    
                    $this->info("Actualizado $modulo para sucursal ID $sucursalId: Siguiente número = $siguiente");
                    $migrados++;
                } else {
                    $this->line("Módulo $modulo (Sucursal $sucursalId) ya tiene un número mayor o igual ({$detalle->siguiente_numero}). No se actualizó.");
                }
            } else {
                $this->warn("No se encontró configuración nueva de secuencia para el módulo $modulo (Sucursal $sucursalId). ¿Corriste el seeder?");
            }
        }

        $this->info("Migración completada. $migrados contadores actualizados.");
        return 0;
    }
}
