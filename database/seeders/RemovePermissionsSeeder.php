<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class RemovePermissionsSeeder extends Seeder
{
    public function run()
    {
        // Cambiar el email según necesites
        $email = 'HCRAMIREZR@medilaser.com.co';
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            echo "❌ Usuario no encontrado: {$email}\n";
            return;
        }

        echo "👤 Usuario encontrado: {$user->name} ({$user->email})\n";
        echo "📋 Permisos actuales:\n";
        foreach ($user->getAllPermissions() as $permission) {
            echo "   - {$permission->name}\n";
        }

        // OPCIÓN 1: Quitar permisos específicos (descomenta para usar)
        // $permisosAQuitar = ['ver-organizacion', 'ver-empresa'];
        // $user->revokePermissionTo($permisosAQuitar);
        // echo "\n✅ Permisos removidos: " . implode(', ', $permisosAQuitar) . "\n";

        // OPCIÓN 2: Dejar solo algunos permisos (descomenta para usar)
        // $permisosAMantener = ['ver-dashboard'];
        // $user->syncPermissions($permisosAMantener);
        // echo "\n✅ Usuario ahora solo tiene: " . implode(', ', $permisosAMantener) . "\n";

        // OPCIÓN 3: Quitar TODOS los permisos (descomenta para usar)
        // $user->syncPermissions([]);
        // echo "\n✅ Todos los permisos removidos\n";

        echo "\n📋 Permisos finales:\n";
        $permisosFinal = $user->getAllPermissions();
        if ($permisosFinal->isEmpty()) {
            echo "   (ninguno)\n";
        } else {
            foreach ($permisosFinal as $permission) {
                echo "   - {$permission->name}\n";
            }
        }

        echo "\n💡 Recuerda: El usuario debe cerrar sesión y volver a iniciar para ver los cambios\n";
    }
}
