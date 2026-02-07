<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;
use App\Models\Empresa;

class RolesSeeder extends Seeder
{
    /**
     * Seed de roles del sistema
     */
    public function run(): void
    {
        echo "🔐 Creando roles del sistema...\n\n";

        // ROLES GLOBALES (sin empresa específica)
        
        // 1. Super Administrador
        $superAdmin = Rol::create([
            'nombre' => 'Super Administrador',
            'codigo' => 'super-admin',
            'id_empresa' => null, // Global
            'descripcion' => 'Acceso total al sistema, puede gestionar todo',
            'es_admin' => true,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$superAdmin->nombre} (Global)\n";

        // 2. Administrador
        $admin = Rol::create([
            'nombre' => 'Administrador',
            'codigo' => 'admin',
            'id_empresa' => null, // Global
            'descripcion' => 'Administrador con permisos completos excepto configuración del sistema',
            'es_admin' => true,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$admin->nombre} (Global)\n";

        // 3. Gerente
        $gerente = Rol::create([
            'nombre' => 'Gerente',
            'codigo' => 'gerente',
            'id_empresa' => null, // Global
            'descripcion' => 'Puede gestionar empresas, sucursales y usuarios',
            'es_admin' => false,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$gerente->nombre} (Global)\n";

        // 4. Supervisor
        $supervisor = Rol::create([
            'nombre' => 'Supervisor',
            'codigo' => 'supervisor',
            'id_empresa' => null, // Global
            'descripcion' => 'Puede ver y editar información, sin permisos de eliminación',
            'es_admin' => false,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$supervisor->nombre} (Global)\n";

        // 5. Operador
        $operador = Rol::create([
            'nombre' => 'Operador',
            'codigo' => 'operador',
            'id_empresa' => null, // Global
            'descripcion' => 'Puede crear y editar registros básicos',
            'es_admin' => false,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$operador->nombre} (Global)\n";

        // 6. Consultor
        $consultor = Rol::create([
            'nombre' => 'Consultor',
            'codigo' => 'consultor',
            'id_empresa' => null, // Global
            'descripcion' => 'Solo lectura, puede ver información pero no modificar',
            'es_admin' => false,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$consultor->nombre} (Global)\n";

        echo "\n";

        // ROLES ESPECÍFICOS POR EMPRESA (si existen empresas)
        $empresas = Empresa::take(2)->get();

        if ($empresas->count() > 0) {
            echo "📋 Creando roles específicos por empresa...\n";

            foreach ($empresas as $empresa) {
                // Administrador de Empresa
                $adminEmpresa = Rol::create([
                    'nombre' => "Administrador {$empresa->nombre}",
                    'codigo' => "admin-{$empresa->prefijo}",
                    'id_empresa' => $empresa->id,
                    'descripcion' => "Administrador exclusivo de {$empresa->nombre}",
                    'es_admin' => false,
                    'estado' => true
                ]);
                echo "  ✅ Creado: {$adminEmpresa->nombre}\n";

                // Usuario de Empresa
                $usuarioEmpresa = Rol::create([
                    'nombre' => "Usuario {$empresa->nombre}",
                    'codigo' => "usuario-{$empresa->prefijo}",
                    'id_empresa' => $empresa->id,
                    'descripcion' => "Usuario estándar de {$empresa->nombre}",
                    'es_admin' => false,
                    'estado' => true
                ]);
                echo "  ✅ Creado: {$usuarioEmpresa->nombre}\n";
            }
        }

        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "📊 RESUMEN:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        $totalRoles = Rol::count();
        $rolesGlobales = Rol::globales()->count();
        $rolesEmpresa = Rol::whereNotNull('id_empresa')->count();
        $rolesAdmin = Rol::administradores()->count();
        
        echo "  📦 Total de roles:        {$totalRoles}\n";
        echo "  🌐 Roles globales:        {$rolesGlobales}\n";
        echo "  🏢 Roles por empresa:     {$rolesEmpresa}\n";
        echo "  👑 Roles administradores: {$rolesAdmin}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        echo "✅ Roles creados exitosamente!\n\n";
        
        echo "💡 NOTA: Los roles están creados pero sin perfiles asignados.\n";
        echo "   Usa el módulo de Roles en el frontend para asignar perfiles.\n";
    }
}
