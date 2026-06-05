<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Actualizar unidades funcionales con id_sede basado en la empresa
        // Para cada empresa, asignar la primera sede disponible
        
        $empresas = \DB::table('ent_empresas')->select('id')->get();
        
        foreach ($empresas as $empresa) {
            // Obtener la primera sede de esta empresa
            $sede = \DB::table('config_ubi_sede')
                ->join('config_ubi_sucursales', 'config_ubi_sede.id_sucursal', '=', 'config_ubi_sucursales.id')
                ->where('config_ubi_sucursales.id_empresa', $empresa->id)
                ->select('config_ubi_sede.id')
                ->first();
            
            if ($sede) {
                // Actualizar todas las unidades funcionales de esta empresa
                \DB::table('config_unidades_funcionales')
                    ->where('id_empresa', $empresa->id)
                    ->whereNull('id_sede')
                    ->update(['id_sede' => $sede->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir los cambios
        \DB::table('config_unidades_funcionales')
            ->update(['id_sede' => null]);
    }
};
