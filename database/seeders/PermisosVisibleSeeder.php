<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Modulo;
use App\Models\Permiso;

class PermisosVisibleSeeder extends Seeder
{
    /**
     * Crear permisos -visible para todos los módulos
     */
    public function run(): void
    {
        $modulos = Modulo::activos()->get();
        
        foreach ($modulos as $modulo) {
            $codigoModulo = strtolower(str_replace('_', '-', $modulo->codigo));
            $codigoPermiso = "{$codigoModulo}-visible";
            
            // Verificar si ya existe
            $existe = Permiso::where('codigo', $codigoPermiso)->exists();
            
            if (!$existe) {
                Permiso::create([
                    'id_modulo' => $modulo->id,
                    'nombre' => 'Visible en Menú',
                    'codigo' => $codigoPermiso,
                    'descripcion' => "Permite visualizar el módulo '{$modulo->nombre}' en el sidebar sin permisos CRUD",
                    'tipo' => 'menu',
                    'orden' => 0,
                    'estado' => true
                ]);
                
                $this->command->info("✅ Permiso creado: {$codigoPermiso}");
            } else {
                $this->command->warn("⚠️  Permiso ya existe: {$codigoPermiso}");
            }
        }
        
        $this->command->info("🎉 Proceso completado");
    }
}
