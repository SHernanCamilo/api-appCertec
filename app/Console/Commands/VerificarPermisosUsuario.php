<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class VerificarPermisosUsuario extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permisos:verificar {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica los permisos de un usuario específico';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        
        $this->info("🔍 Verificando permisos para: {$email}");
        $this->newLine();
        
        // Buscar usuario
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("❌ Usuario no encontrado");
            return 1;
        }
        
        $this->info("✅ Usuario encontrado:");
        $this->line("   ID: {$user->id}");
        $this->line("   Nombre: {$user->name}");
        $this->line("   Email: {$user->email}");
        $this->newLine();
        
        // Cargar relaciones
        $user->load(['rolesCustom.perfiles.permisos', 'empresas']);
        
        // Mostrar roles
        $this->info("📋 ROLES ({$user->rolesCustom->count()}):");
        if ($user->rolesCustom->isEmpty()) {
            $this->warn("   ⚠️  No tiene roles asignados");
        } else {
            foreach ($user->rolesCustom as $rol) {
                $this->line("   • {$rol->nombre} (ID: {$rol->id}, Código: {$rol->codigo})");
            }
        }
        $this->newLine();
        
        // Mostrar perfiles por rol
        $totalPerfiles = 0;
        foreach ($user->rolesCustom as $rol) {
            $this->info("🔑 PERFILES DEL ROL: {$rol->nombre} ({$rol->perfiles->count()}):");
            if ($rol->perfiles->isEmpty()) {
                $this->warn("   ⚠️  Este rol no tiene perfiles asignados");
            } else {
                foreach ($rol->perfiles as $perfil) {
                    $totalPerfiles++;
                    $moduloNombre = $perfil->modulo ? $perfil->modulo->nombre : 'N/A';
                    $this->line("   • {$perfil->nombre} (ID: {$perfil->id}, Código: {$perfil->codigo})");
                    $this->line("     Módulo: {$moduloNombre}");
                    $crud = ($perfil->puede_crear ? 'C' : '-') .
                            ($perfil->puede_leer ? 'R' : '-') .
                            ($perfil->puede_editar ? 'U' : '-') .
                            ($perfil->puede_eliminar ? 'D' : '-');
                    $this->line("     CRUD: {$crud}");
                    $this->line("     Permisos extra: {$perfil->permisos->count()}");
                }
            }
            $this->newLine();
        }
        
        // Obtener todos los permisos únicos
        $permisos = collect();
        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                $permisos = $permisos->merge($perfil->permisos);
            }
        }
        
        $permisosUnicos = $permisos->unique('id');
        
        // Mostrar permisos
        $this->info("🔐 PERMISOS TOTALES ({$permisosUnicos->count()}):");
        if ($permisosUnicos->isEmpty()) {
            $this->error("   ❌ No tiene permisos asignados");
            $this->newLine();
            $this->warn("💡 POSIBLES CAUSAS:");
            $this->line("   1. El usuario no tiene roles asignados");
            $this->line("   2. Los roles no tienen perfiles asignados");
            $this->line("   3. Los perfiles no tienen permisos configurados");
            $this->newLine();
            $this->info("📝 SOLUCIÓN:");
            $this->line("   1. Asignar rol al usuario en: /organizacion/usuario/roles");
            $this->line("   2. Asignar perfiles al rol en: /organizacion/usuario/roles (botón de perfiles)");
            $this->line("   3. Verificar que los perfiles tengan permisos en: /organizacion/usuario/perfiles");
        } else {
            $permisosAgrupados = $permisosUnicos->groupBy('tipo');
            
            foreach ($permisosAgrupados as $tipo => $perms) {
                $this->line("   {$tipo} ({$perms->count()}):");
                foreach ($perms as $permiso) {
                    $estado = $permiso->estado ? '✓' : '✗';
                    $this->line("      {$estado} {$permiso->codigo} - {$permiso->nombre}");
                }
            }
        }
        $this->newLine();
        
        // Mostrar empresas
        $this->info("🏢 EMPRESAS ({$user->empresas->count()}):");
        if ($user->empresas->isEmpty()) {
            $this->warn("   ⚠️  No tiene empresas asignadas");
        } else {
            foreach ($user->empresas as $empresa) {
                $this->line("   • {$empresa->nombre} (NIT: {$empresa->nit})");
            }
        }
        $this->newLine();
        
        // Resumen
        $this->info("📊 RESUMEN:");
        $this->line("   Roles: {$user->rolesCustom->count()}");
        $this->line("   Perfiles: {$totalPerfiles}");
        $this->line("   Permisos únicos: {$permisosUnicos->count()}");
        $this->line("   Empresas: {$user->empresas->count()}");
        
        return 0;
    }
}
