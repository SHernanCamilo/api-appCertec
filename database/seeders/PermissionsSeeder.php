<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class PermissionsSeeder extends Seeder
{
    public function run()
    {
        // Crear permisos si no existen
        $permissions = [
            'ver-dashboard',
            'ver-organizacion',
            'ver-empresa',
            'ver-maestro-empresa',
            'ver-sucursales',
            'ver-sedes',
            'ver-usuarios',
            'crear-usuario',
            'ver-roles',
            'ver-perfiles',
            'ver-servicios',
            'ver-modulos',
            'ver-reportes',
            'ver-configuracion',
        ];

        // Crear permisos para ambos guards
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Asignar permisos al usuario específico
        $user = User::where('email', 'HCRAMIREZR@medilaser.com.co')->first();
        
        if ($user) {
            // Asignar permisos con el guard correcto
            foreach ($permissions as $permission) {
                $user->givePermissionTo($permission);
            }
            echo "Permisos asignados correctamente a {$user->email}\n";
        } else {
            echo "Usuario no encontrado\n";
        }
    }
}
