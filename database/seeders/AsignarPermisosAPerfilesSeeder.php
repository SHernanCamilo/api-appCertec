<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Perfil;
use App\Models\Permiso;

class AsignarPermisosAPerfilesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🔗 Asignando permisos a perfiles...');

        // Obtener todos los perfiles y permisos
        $perfiles = Perfil::all();
        $permisos = Permiso::all();

        if ($perfiles->isEmpty() || $permisos->isEmpty()) {
            $this->command->warn('⚠️  No hay perfiles o permisos creados');
            return;
        }

        // Asignar permisos según el módulo del perfil
        foreach ($perfiles as $perfil) {
            // Obtener permisos del mismo módulo que el perfil
            $permisosDelModulo = $permisos->where('id_modulo', $perfil->id_modulo);
            
            if ($permisosDelModulo->isEmpty()) {
                continue;
            }

            $permisosAsignar = [];

            // Asignar permisos según las capacidades del perfil
            foreach ($permisosDelModulo as $permiso) {
                $asignar = false;

                // Verificar si el perfil tiene el permiso correspondiente
                if ($permiso->codigo === 'empresas.crear' && $perfil->puede_crear) {
                    $asignar = true;
                }
                if ($permiso->codigo === 'empresas.ver' && $perfil->puede_leer) {
                    $asignar = true;
                }
                if ($permiso->codigo === 'empresas.editar' && $perfil->puede_editar) {
                    $asignar = true;
                }
                if ($permiso->codigo === 'empresas.eliminar' && $perfil->puede_eliminar) {
                    $asignar = true;
                }

                // Para otros permisos, asignar todos si el perfil puede leer
                if (strpos($permiso->codigo, 'empresas.') === 0 && $perfil->puede_leer) {
                    $asignar = true;
                }

                if ($asignar) {
                    $permisosAsignar[] = $permiso->id;
                }
            }

            // Sincronizar permisos
            if (!empty($permisosAsignar)) {
                $perfil->permisos()->sync($permisosAsignar);
                $this->command->info("  ✅ Perfil '{$perfil->nombre}': " . count($permisosAsignar) . " permisos asignados");
            }
        }

        $this->command->info('🎉 Permisos asignados a perfiles exitosamente');
    }
}
