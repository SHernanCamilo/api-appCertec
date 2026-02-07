<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Rol;
use App\Models\Empresa;
use Illuminate\Support\Facades\Hash;

class UsuariosConRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener roles y empresas
        $rolSuperAdmin = Rol::where('codigo', 'super-admin')->first();
        $rolAdmin = Rol::where('codigo', 'admin')->first();
        $rolGerente = Rol::where('codigo', 'gerente')->first();
        $rolVendedor = Rol::where('codigo', 'vendedor')->first();
        
        $empresas = Empresa::limit(2)->get();
        
        $this->command->info('Roles encontrados: ' . Rol::count());
        $this->command->info('Empresas encontradas: ' . $empresas->count());
        
        if (!$rolSuperAdmin || !$rolAdmin) {
            $this->command->warn('⚠️  No se encontraron los roles necesarios');
            $this->command->warn('Super Admin: ' . ($rolSuperAdmin ? 'OK' : 'NO'));
            $this->command->warn('Admin: ' . ($rolAdmin ? 'OK' : 'NO'));
            return;
        }
        
        if ($empresas->isEmpty()) {
            $this->command->warn('⚠️  No hay empresas creadas');
            return;
        }

        // Usuario 1: Super Admin (acceso a todas las empresas)
        $superAdmin = User::where('email', 'admin@sistema.com')->first();
        if (!$superAdmin) {
            $superAdmin = User::create([
                'name' => 'Super Administrador',
                'email' => 'admin@sistema.com',
                'password' => Hash::make('password123'),
            ]);
        }
        // Usar la relación personalizada
        $superAdmin->rolesCustom()->sync([$rolSuperAdmin->id]);
        $superAdmin->empresas()->sync($empresas->pluck('id'));
        $this->command->info('✅ Usuario Super Admin creado/actualizado');

        // Usuario 2: Admin de Empresa 1
        if ($empresas->count() > 0) {
            $adminEmpresa1 = User::where('email', 'admin.empresa1@sistema.com')->first();
            if (!$adminEmpresa1) {
                $adminEmpresa1 = User::create([
                    'name' => 'Admin Empresa 1',
                    'email' => 'admin.empresa1@sistema.com',
                    'password' => Hash::make('password123'),
                ]);
            }
            $adminEmpresa1->rolesCustom()->sync([$rolAdmin->id]);
            $adminEmpresa1->empresas()->sync([$empresas[0]->id]);
            $this->command->info('✅ Usuario Admin Empresa 1 creado/actualizado');
        }

        // Usuario 3: Gerente de Empresa 2
        if ($empresas->count() > 1 && $rolGerente) {
            $gerente = User::where('email', 'gerente@empresa2.com')->first();
            if (!$gerente) {
                $gerente = User::create([
                    'name' => 'Gerente Empresa 2',
                    'email' => 'gerente@empresa2.com',
                    'password' => Hash::make('password123'),
                ]);
            }
            $gerente->rolesCustom()->sync([$rolGerente->id]);
            $gerente->empresas()->sync([$empresas[1]->id]);
            $this->command->info('✅ Usuario Gerente creado/actualizado');
        }

        // Usuario 4: Vendedor sin empresa asignada
        if ($rolVendedor) {
            $vendedor = User::where('email', 'vendedor@sistema.com')->first();
            if (!$vendedor) {
                $vendedor = User::create([
                    'name' => 'Vendedor Sin Empresa',
                    'email' => 'vendedor@sistema.com',
                    'password' => Hash::make('password123'),
                ]);
            }
            $vendedor->rolesCustom()->sync([$rolVendedor->id]);
            $this->command->info('✅ Usuario Vendedor creado/actualizado');
        }

        $this->command->info('🎉 Usuarios con roles y empresas creados exitosamente');
    }
}
