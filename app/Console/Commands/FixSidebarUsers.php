<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\Perfil;
use App\Models\Empresa;
use App\Models\ModuloEmpresa;
use Illuminate\Support\Facades\DB;

class FixSidebarUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sidebar:fix-users 
                            {--user-id= : ID específico de usuario a arreglar}
                            {--email= : Email específico de usuario a arreglar}
                            {--dry-run : Solo mostrar qué se haría sin ejecutar cambios}
                            {--create-missing : Crear datos faltantes automáticamente}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Arregla problemas comunes que impiden que los usuarios tengan sidebar dinámico';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 REPARADOR DE SIDEBAR DINÁMICO');
        $this->line(str_repeat('=', 80));

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn('⚠️ MODO DRY-RUN: Solo se mostrarán los cambios sin ejecutarlos');
        }

        // Obtener usuarios a arreglar
        $usuarios = $this->getUsuarios();

        if ($usuarios->isEmpty()) {
            $this->error('❌ No se encontraron usuarios para arreglar');
            return 1;
        }

        $this->info("\n🔨 ARREGLANDO {$usuarios->count()} USUARIOS:");
        $this->line(str_repeat('-', 80));

        $arreglados = 0;
        $errores = 0;

        foreach ($usuarios as $usuario) {
            try {
                if ($this->arreglarUsuario($usuario, $dryRun)) {
                    $arreglados++;
                }
            } catch (\Exception $e) {
                $this->error("❌ Error arreglando {$usuario->name}: " . $e->getMessage());
                $errores++;
            }
        }

        // Resumen final
        $this->line("\n" . str_repeat('=', 80));
        $this->info('📈 RESUMEN:');
        $this->line("   ✅ Usuarios arreglados: {$arreglados}");
        $this->line("   ❌ Errores: {$errores}");
        $this->line("   📊 Total procesados: {$usuarios->count()}");

        if ($dryRun) {
            $this->line("\n💡 Para aplicar los cambios, ejecuta el comando sin --dry-run");
        } else {
            $this->line("\n💡 Ejecuta 'php artisan sidebar:validate-users' para verificar los cambios");
        }

        return 0;
    }

    private function getUsuarios()
    {
        if ($this->option('user-id')) {
            return User::where('id', $this->option('user-id'))->get();
        }

        if ($this->option('email')) {
            return User::where('email', $this->option('email'))->get();
        }

        // Por defecto, usuarios activos sin sidebar
        return User::where('estado', 1)
            ->with(['rolesCustom.perfiles.modulo', 'empresas'])
            ->get()
            ->filter(function ($usuario) {
                // Filtrar usuarios que probablemente no tengan sidebar
                return $usuario->rolesCustom->count() == 0 || 
                       $usuario->rolesCustom->flatMap->perfiles->where('puede_leer', true)->count() == 0;
            });
    }

    private function arreglarUsuario(User $usuario, bool $dryRun): bool
    {
        $this->line("\n🔍 Analizando: {$usuario->name} ({$usuario->email})");
        
        $cambios = [];
        $necesitaCambios = false;

        // Cargar relaciones
        $usuario->load(['rolesCustom.perfiles.modulo', 'empresas']);

        // 1. Verificar si está activo
        if (!$usuario->estado) {
            $cambios[] = "Activar usuario";
            $necesitaCambios = true;
            if (!$dryRun) {
                $usuario->update(['estado' => 1]);
            }
        }

        // 2. Verificar roles
        if ($usuario->rolesCustom->count() == 0) {
            $cambios[] = "Asignar rol básico";
            $necesitaCambios = true;
            
            if (!$dryRun) {
                $this->asignarRolBasico($usuario);
            }
        }

        // 3. Verificar empresa
        if ($usuario->empresas->count() == 0) {
            $cambios[] = "Asignar empresa principal";
            $necesitaCambios = true;
            
            if (!$dryRun) {
                $this->asignarEmpresaPrincipal($usuario);
            }
        }

        // 4. Verificar perfiles con lectura
        $perfiles = $usuario->rolesCustom->flatMap->perfiles;
        $perfilesConLectura = $perfiles->where('puede_leer', true);
        
        if ($perfilesConLectura->count() == 0 && $perfiles->count() > 0) {
            $cambios[] = "Habilitar permisos de lectura en perfiles";
            $necesitaCambios = true;
            
            if (!$dryRun) {
                $this->habilitarLecturaEnPerfiles($usuario);
            }
        }

        // 5. Crear datos faltantes si se solicita
        if ($this->option('create-missing') && $usuario->rolesCustom->count() == 0) {
            $cambios[] = "Crear rol y perfil personalizado";
            $necesitaCambios = true;
            
            if (!$dryRun) {
                $this->crearDatosPersonalizados($usuario);
            }
        }

        // Mostrar cambios
        if ($necesitaCambios) {
            $icon = $dryRun ? '📋' : '✅';
            $this->line("   {$icon} Cambios " . ($dryRun ? 'propuestos' : 'aplicados') . ":");
            foreach ($cambios as $cambio) {
                $this->line("      • {$cambio}");
            }
        } else {
            $this->line("   ℹ️  No necesita cambios");
        }

        return $necesitaCambios;
    }

    private function asignarRolBasico(User $usuario)
    {
        // Buscar rol básico existente
        $rol = Rol::where('codigo', 'USUARIO-BASICO')
            ->where('estado', 1)
            ->first();

        if (!$rol) {
            // Crear rol básico si no existe
            $empresa = Empresa::where('estado', 1)->first();
            
            $rol = Rol::create([
                'nombre' => 'Usuario Básico',
                'codigo' => 'USUARIO-BASICO',
                'id_empresa' => $empresa?->id,
                'descripcion' => 'Rol básico creado automáticamente',
                'es_admin' => 0,
                'estado' => 1
            ]);

            // Crear perfil básico para el rol
            $this->crearPerfilBasico($rol);
        }

        // Asignar rol al usuario
        $usuario->rolesCustom()->syncWithoutDetaching([$rol->id]);
    }

    private function crearPerfilBasico(Rol $rol)
    {
        // Buscar módulo básico (Dashboard o el primero disponible)
        $modulo = Modulo::where('estado', 1)
            ->where(function ($query) {
                $query->where('codigo', 'LIKE', '%DASHBOARD%')
                      ->orWhere('nombre', 'LIKE', '%Dashboard%');
            })
            ->first();

        if (!$modulo) {
            $modulo = Modulo::where('estado', 1)->first();
        }

        if ($modulo) {
            $perfil = Perfil::create([
                'nombre' => 'Visualizador Básico',
                'codigo' => 'BASICO-VIEWER-' . time(),
                'id_modulo' => $modulo->id,
                'descripcion' => 'Perfil básico creado automáticamente',
                'puede_crear' => 0,
                'puede_leer' => 1,
                'puede_editar' => 0,
                'puede_eliminar' => 0,
                'estado' => 1
            ]);

            // Asignar perfil al rol
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
        }
    }

    private function asignarEmpresaPrincipal(User $usuario)
    {
        $empresa = Empresa::where('estado', 1)->first();
        
        if ($empresa) {
            $usuario->empresas()->syncWithoutDetaching([$empresa->id => [
                'id_sucursal' => null,
                'id_sede' => null,
                'recursivo' => 1
            ]]);
        }
    }

    private function habilitarLecturaEnPerfiles(User $usuario)
    {
        foreach ($usuario->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                if (!$perfil->puede_leer) {
                    $perfil->update(['puede_leer' => 1]);
                }
            }
        }
    }

    private function crearDatosPersonalizados(User $usuario)
    {
        DB::transaction(function () use ($usuario) {
            // Obtener empresa principal
            $empresa = Empresa::where('estado', 1)->first();
            
            if (!$empresa) {
                $empresa = Empresa::create([
                    'nombre' => 'Empresa Principal',
                    'codigo' => 'EMP-PRINCIPAL',
                    'descripcion' => 'Empresa creada automáticamente',
                    'estado' => 1
                ]);
            }

            // Crear rol personalizado
            $rol = Rol::create([
                'nombre' => "Rol {$usuario->name}",
                'codigo' => 'ROL-' . strtoupper(str_replace(' ', '-', $usuario->name)) . '-' . time(),
                'id_empresa' => $empresa->id,
                'descripcion' => "Rol personalizado para {$usuario->name}",
                'es_admin' => 0,
                'estado' => 1
            ]);

            // Buscar módulos disponibles
            $modulos = Modulo::where('estado', 1)
                ->whereNotNull('ruta')
                ->limit(3)
                ->get();

            foreach ($modulos as $modulo) {
                // Crear perfil para cada módulo
                $perfil = Perfil::create([
                    'nombre' => "Acceso {$modulo->nombre}",
                    'codigo' => 'PERFIL-' . $modulo->codigo . '-' . $usuario->id . '-' . time(),
                    'id_modulo' => $modulo->id,
                    'descripcion' => "Perfil personalizado para {$modulo->nombre}",
                    'puede_crear' => 0,
                    'puede_leer' => 1,
                    'puede_editar' => 0,
                    'puede_eliminar' => 0,
                    'estado' => 1
                ]);

                // Asignar perfil al rol
                $rol->perfiles()->attach($perfil->id);

                // Asegurar que el módulo esté asignado a la empresa
                ModuloEmpresa::firstOrCreate([
                    'id_modulo' => $modulo->id,
                    'id_empresa' => $empresa->id
                ], [
                    'activo' => 1,
                    'hereda_hijos' => 1
                ]);
            }

            // Asignar rol y empresa al usuario
            $usuario->rolesCustom()->syncWithoutDetaching([$rol->id]);
            $usuario->empresas()->syncWithoutDetaching([$empresa->id => [
                'id_sucursal' => null,
                'id_sede' => null,
                'recursivo' => 1
            ]]);
        });
    }
}