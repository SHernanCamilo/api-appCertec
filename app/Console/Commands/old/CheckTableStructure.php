<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CheckTableStructure extends Command
{
    protected $signature = 'check:table-structure';
    protected $description = 'Verificar estructura de la tabla matzobs_activos_c';

    public function handle()
    {
        $this->info('📋 Estructura actual de matzobs_activos_c:');
        
        $columns = DB::select("DESCRIBE matzobs_activos_c");
        
        foreach($columns as $column) {
            $this->line("• {$column->Field} - {$column->Type} - {$column->Null} - {$column->Key}");
        }
        
        return 0;
    }
}