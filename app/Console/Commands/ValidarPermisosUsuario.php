<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Modulo;
use Illuminate\Console\Command;

class ValidarPermisosUsuario extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'usuarios:validar-permisos {identificador? : ID o Email del usuario (opcional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Valida los permisos de usuarios y muestra los módulos que verían en el sidebar';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $identificador = $this->argument('identificador');

        if ($identificador) {
            // Validar un usuario específico por ID o Email
            $this->validarUsuarioPorIdentificador($identificador);
        } else {
            // Mostrar lista de usuarios para seleccionar
            $this->mostrarListaUsuarios();
        }

        return 0;
    }

    /**
     * Validar usuario por ID o Email
     */
    private function validarUsuarioPorIdentificador($identificador)
    {
        // Intentar buscar por ID si es numérico
        if (is_numeric($identificador)) {
            $user = User::with(['rolesCustom.perfiles.modulo', 'empresas'])->find($identificador);
            
            if ($user) {
                $this->validarUsuario($user);
                return;
            }
        }

        // Buscar por email
        $user = User::with(['rolesCustom.perfiles.modulo', 'empresas'])
            ->where('email', $identificador)
            ->first();

        if ($user) {
            $this->validarUsuario($user);
            return;
        }

        // No encontrado
        $this->error("❌ Usuario no encontrado con el identificador: {$identificador}");
        $this->newLine();
        $this->warn("💡 Puedes buscar por:");
        $this->line("   - ID del usuario (ejemplo: 1)");
        $this->line("   - Email del usuario (ejemplo: usuario@example.com)");
        $this->newLine();
        $this->info("📋 Para ver la lista de usuarios, ejecuta:");
        $this->line("   php artisan usuarios:validar-permisos");
    }

    /**
     * Mostrar lista de usuarios
     */
    private function mostrarListaUsuarios()
    {
        $usuarios = User::with('rolesCustom')->get();

        if ($usuarios->isEmpty()) {
            $this->error('No hay usuarios en el sistema');
            return;
        }

        $this->info('=== LISTA DE USUARIOS ===');
        $this->newLine();

        $headers = ['ID', 'Nombre', 'Email', 'Roles', 'Es Admin'];
        $rows = [];

        foreach ($usuarios as $user) {
            $roles = $user->rolesCustom->pluck('nombre')->join(', ') ?: 'Sin roles';
            $esAdmin = $user->rolesCustom->where('es_admin', true)->isNotEmpty() ? '✓ Sí' : '✗ No';
            
            $rows[] = [
                $user->id,
                $user->name,
                $user->email,
                $roles,
                $esAdmin
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();

        // Preguntar si quiere ver detalles de algún usuario
        if ($this->confirm('¿Deseas ver los detalles de algún usuario?', true)) {
            $identificador = $this->ask('Ingresa el ID o Email del usuario');
            $this->newLine();
            $this->validarUsuarioPorIdentificador($identificador);
        }
    }

    /**
     * Validar permisos de un usuario específico
     */
    private function validarUsuario($user)
    {

        $this->info("╔════════════════════════════════════════════════════════════════╗");
        $this->info("║  VALIDACIÓN DE PERMISOS - USUARIO: {$user->name}");
        $this->info("╚════════════════════════════════════════════════════════════════╝");
        $this->newLine();

        // 1. Información básica
        $this->line("📧 Email: {$user->email}");
        $this->line("🆔 ID: {$user->id}");
        $this->newLine();

        // 2. Validar roles
        $this->info('🔐 ROLES ASIGNADOS:');
        if ($user->rolesCustom->isEmpty()) {
            $this->error('   ✗ El usuario NO tiene roles asignados');
            $this->warn('   ⚠ Sin roles, el usuario NO verá ningún módulo');
            $this->newLine();
            return;
        }

        foreach ($user->rolesCustom as $rol) {
            $adminBadge = $rol->es_admin ? '👑 ADMIN' : '';
            $this->line("   ✓ {$rol->nombre} {$adminBadge}");
            $this->line("     - ID: {$rol->id}");
            $this->line("     - Es Admin: " . ($rol->es_admin ? 'Sí' : 'No'));
            $this->line("     - Perfiles: " . $rol->perfiles->count());
        }
        $this->newLine();

        // 3. Verificar si es super admin
        $esSuperAdmin = $user->rolesCustom->where('es_admin', true)->isNotEmpty();
        if ($esSuperAdmin) {
            $this->info('👑 SUPER ADMIN DETECTADO');
            $this->line('   ✓ Este usuario tiene acceso a TODOS los módulos');
            $this->newLine();
        }

        // 4. Mostrar perfiles y permisos
        $this->info('📋 PERFILES Y PERMISOS:');
        $tienePerfiles = false;

        foreach ($user->rolesCustom as $rol) {
            if ($rol->perfiles->isNotEmpty()) {
                $tienePerfiles = true;
                $this->line("   Rol: {$rol->nombre}");
                
                foreach ($rol->perfiles as $perfil) {
                    $modulo = $perfil->modulo;
                    $permisos = [];
                    if ($perfil->puede_leer) $permisos[] = 'Leer';
                    if ($perfil->puede_crear) $permisos[] = 'Crear';
                    if ($perfil->puede_editar) $permisos[] = 'Editar';
                    if ($perfil->puede_eliminar) $permisos[] = 'Eliminar';
                    
                    $permisosStr = !empty($permisos) ? implode(', ', $permisos) : 'Sin permisos';
                    $icon = $perfil->puede_leer ? '✓' : '✗';
                    
                    $this->line("     {$icon} Módulo: {$modulo->nombre} ({$modulo->codigo})");
                    $this->line("       Permisos: {$permisosStr}");
                }
            }
        }

        if (!$tienePerfiles && !$esSuperAdmin) {
            $this->warn('   ⚠ El usuario NO tiene perfiles asignados');
            $this->warn('   ⚠ Sin perfiles ni rol admin, NO verá ningún módulo');
        }
        $this->newLine();

        // 5. Simular módulos que vería en el sidebar
        $this->info('🎯 MÓDULOS QUE VERÍA EN EL SIDEBAR:');
        $modulosVisibles = $this->obtenerModulosVisibles($user);

        if ($modulosVisibles->isEmpty()) {
            $this->error('   ✗ NO vería ningún módulo');
            $this->newLine();
            $this->warn('💡 SOLUCIÓN:');
            $this->line('   1. Asignar un rol con es_admin = true, O');
            $this->line('   2. Asignar perfiles con puede_leer = true a los módulos deseados');
        } else {
            $this->renderizarArbolModulos($modulosVisibles);
            $this->newLine();
            $this->info("   Total: {$modulosVisibles->count()} módulos visibles");
        }

        $this->newLine();

        // 6. Empresas asociadas
        $this->info('🏢 EMPRESAS ASOCIADAS:');
        if ($user->empresas->isEmpty()) {
            $this->warn('   ⚠ Sin empresas asociadas');
        } else {
            foreach ($user->empresas as $empresa) {
                $this->line("   ✓ {$empresa->nombre_comercial}");
            }
        }

        $this->newLine();
    }

    /**
     * Obtener módulos visibles para el usuario
     */
    private function obtenerModulosVisibles($user)
    {
        $modulos = Modulo::whereNull('id_modulo_padre')
            ->where('estado', 1)
            ->orderBy('orden')
            ->with(['hijos' => function($query) {
                $query->where('estado', 1)->orderBy('orden');
            }])
            ->get();

        $modulosVisibles = collect();

        foreach ($modulos as $modulo) {
            if ($this->usuarioTienePermisoModulo($user, $modulo)) {
                $modulosVisibles->push($modulo);
            }
        }

        return $modulosVisibles;
    }

    /**
     * Verificar si el usuario tiene permiso para un módulo
     */
    private function usuarioTienePermisoModulo($user, $modulo)
    {
        if (!$user->rolesCustom || $user->rolesCustom->isEmpty()) {
            return false;
        }

        // Si es super admin, tiene acceso a todo
        foreach ($user->rolesCustom as $rol) {
            if ($rol->es_admin) {
                return true;
            }
        }

        // Verificar perfiles
        foreach ($user->rolesCustom as $rol) {
            if (!$rol->perfiles) {
                continue;
            }
            
            foreach ($rol->perfiles as $perfil) {
                // Verificar permiso directo en el módulo actual
                if ($perfil->id_modulo == $modulo->id && $perfil->puede_leer) {
                    return true;
                }
                
                // Verificar si tiene permiso en algún hijo del módulo
                if ($this->tienePermisoEnHijos($modulo, $perfil)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verificar si el perfil tiene permiso en algún hijo del módulo (recursivo)
     */
    private function tienePermisoEnHijos($modulo, $perfil)
    {
        if (!$modulo->hijos || $modulo->hijos->isEmpty()) {
            return false;
        }

        foreach ($modulo->hijos as $hijo) {
            // Verificar permiso en el hijo
            if ($perfil->id_modulo == $hijo->id && $perfil->puede_leer) {
                return true;
            }
            
            // Verificar recursivamente en los hijos del hijo
            if ($this->tienePermisoEnHijos($hijo, $perfil)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renderizar árbol de módulos
     */
    private function renderizarArbolModulos($modulos, $nivel = 0)
    {
        foreach ($modulos as $modulo) {
            $indent = str_repeat('   ', $nivel);
            $icon = $nivel === 0 ? '📁' : '└─';
            $this->line("{$indent}{$icon} {$modulo->nombre} ({$modulo->codigo})");
            $this->line("{$indent}   Ruta: {$modulo->ruta}");
            $this->line("{$indent}   Icono: {$modulo->icono}");
            
            if ($modulo->hijos && $modulo->hijos->isNotEmpty()) {
                $this->renderizarArbolModulos($modulo->hijos, $nivel + 1);
            }
        }
    }
}
