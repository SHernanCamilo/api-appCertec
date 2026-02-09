<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\Perfil;
use App\Models\Empresa;
use App\Models\ModuloEmpresa;
use App\Services\SidebarService;

class ValidateSidebarUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sidebar:validate-users 
                            {--user-id= : ID específico de usuario a validar}
                            {--email= : Email específico de usuario a validar}
                            {--detailed : Mostrar información detallada}
                            {--only-with-modules : Solo mostrar usuarios con módulos}
                            {--only-without-modules : Solo mostrar usuarios sin módulos}
                            {--export= : Exportar resultados a archivo}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Valida qué usuarios tendrían módulos cargados dinámicamente en el sidebar';

    protected $sidebarService;

    public function __construct(SidebarService $sidebarService)
    {
        parent::__construct();
        $this->sidebarService = $sidebarService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 VALIDADOR DE SIDEBAR DINÁMICO');
        $this->line(str_repeat('=', 80));

        // Mostrar estadísticas generales
        $this->showGeneralStats();

        // Obtener usuarios a validar
        $usuarios = $this->getUsuarios();

        if ($usuarios->isEmpty()) {
            $this->error('❌ No se encontraron usuarios para validar');
            return 1;
        }

        $this->info("\n👥 VALIDANDO {$usuarios->count()} USUARIOS:");
        $this->line(str_repeat('-', 80));

        $resultados = [];
        $usuariosConModulos = 0;
        $usuariosSinModulos = 0;

        foreach ($usuarios as $usuario) {
            $resultado = $this->validarUsuario($usuario);
            $resultados[] = $resultado;

            if ($resultado['modulos_count'] > 0) {
                $usuariosConModulos++;
            } else {
                $usuariosSinModulos++;
            }

            // Filtros de visualización
            if ($this->option('only-with-modules') && $resultado['modulos_count'] == 0) {
                continue;
            }
            if ($this->option('only-without-modules') && $resultado['modulos_count'] > 0) {
                continue;
            }

            $this->mostrarResultadoUsuario($resultado);
        }

        // Resumen final
        $this->showSummary($usuariosConModulos, $usuariosSinModulos, $usuarios->count());

        // Exportar si se solicita
        if ($this->option('export')) {
            $this->exportResults($resultados);
        }

        return 0;
    }

    private function showGeneralStats()
    {
        $this->info('📊 ESTADÍSTICAS GENERALES:');
        
        $stats = [
            'Usuarios activos' => User::where('estado', 1)->count(),
            'Usuarios inactivos' => User::where('estado', 0)->count(),
            'Módulos activos' => Modulo::where('estado', 1)->count(),
            'Módulos raíz' => Modulo::whereNull('id_modulo_padre')->where('estado', 1)->count(),
            'Roles activos' => Rol::where('estado', 1)->count(),
            'Perfiles activos' => Perfil::where('estado', 1)->count(),
            'Empresas activas' => Empresa::where('estado', 1)->count(),
            'Asignaciones módulo-empresa' => ModuloEmpresa::where('activo', 1)->count(),
        ];

        foreach ($stats as $label => $count) {
            $icon = $count > 0 ? '✅' : '❌';
            $this->line("   {$icon} {$label}: {$count}");
        }
    }

    private function getUsuarios()
    {
        if ($this->option('user-id')) {
            return User::where('id', $this->option('user-id'))->get();
        }

        if ($this->option('email')) {
            return User::where('email', $this->option('email'))->get();
        }

        // Por defecto, todos los usuarios activos
        return User::where('estado', 1)
            ->with(['rolesCustom.perfiles.modulo', 'rolesCustom.perfiles.permisos', 'empresas'])
            ->orderBy('name')
            ->get();
    }

    private function validarUsuario(User $usuario)
    {
        // Cargar relaciones si no están cargadas
        if (!$usuario->relationLoaded('rolesCustom')) {
            $usuario->load(['rolesCustom.perfiles.modulo', 'rolesCustom.perfiles.permisos', 'empresas']);
        }

        $resultado = [
            'usuario' => $usuario,
            'modulos_count' => 0,
            'modulos' => [],
            'problemas' => [],
            'roles_count' => $usuario->rolesCustom->count(),
            'empresas_count' => $usuario->empresas->count(),
            'perfiles_count' => 0,
            'perfiles_con_lectura' => 0,
            'tiene_sidebar' => false,
            'error' => null
        ];

        try {
            // Contar perfiles
            $perfiles = $usuario->rolesCustom->flatMap->perfiles;
            $resultado['perfiles_count'] = $perfiles->count();
            $resultado['perfiles_con_lectura'] = $perfiles->where('puede_leer', true)->count();

            // Generar sidebar
            $sidebar = $this->sidebarService->getSidebarModules($usuario);
            $resultado['modulos_count'] = count($sidebar);
            $resultado['modulos'] = $sidebar;
            $resultado['tiene_sidebar'] = count($sidebar) > 0;

            // Detectar problemas
            $this->detectarProblemas($usuario, $resultado);

        } catch (\Exception $e) {
            $resultado['error'] = $e->getMessage();
            $resultado['problemas'][] = 'Error al generar sidebar: ' . $e->getMessage();
        }

        return $resultado;
    }

    private function detectarProblemas(User $usuario, &$resultado)
    {
        $problemas = [];

        // Usuario inactivo
        if (!$usuario->estado) {
            $problemas[] = 'Usuario inactivo';
        }

        // Sin roles
        if ($resultado['roles_count'] == 0) {
            $problemas[] = 'Sin roles asignados';
        }

        // Sin perfiles
        if ($resultado['perfiles_count'] == 0) {
            $problemas[] = 'Sin perfiles en los roles';
        }

        // Sin permisos de lectura
        if ($resultado['perfiles_con_lectura'] == 0) {
            $problemas[] = 'Sin permisos de lectura en perfiles';
        }

        // Sin empresas (pero esto puede ser normal para acceso total)
        if ($resultado['empresas_count'] == 0) {
            $problemas[] = 'Sin empresas asignadas (acceso total)';
        }

        // Módulos sin asignar a empresas
        if ($resultado['empresas_count'] > 0) {
            $empresasIds = $usuario->empresas->pluck('id');
            $modulosAsignados = ModuloEmpresa::whereIn('id_empresa', $empresasIds)
                ->where('activo', 1)
                ->count();
            
            if ($modulosAsignados == 0) {
                $problemas[] = 'Las empresas del usuario no tienen módulos asignados';
            }
        }

        $resultado['problemas'] = $problemas;
    }

    private function mostrarResultadoUsuario($resultado)
    {
        $usuario = $resultado['usuario'];
        $icon = $resultado['tiene_sidebar'] ? '✅' : '❌';
        $status = $resultado['tiene_sidebar'] ? 'CON SIDEBAR' : 'SIN SIDEBAR';

        $this->line("\n{$icon} {$usuario->name} ({$usuario->email}) - {$status}");

        if ($this->option('detailed')) {
            $this->line("   📊 Estadísticas:");
            $this->line("      • Roles: {$resultado['roles_count']}");
            $this->line("      • Empresas: {$resultado['empresas_count']}");
            $this->line("      • Perfiles: {$resultado['perfiles_count']}");
            $this->line("      • Perfiles con lectura: {$resultado['perfiles_con_lectura']}");
            $this->line("      • Módulos en sidebar: {$resultado['modulos_count']}");

            if (!empty($resultado['modulos'])) {
                $this->line("   📋 Módulos:");
                foreach ($resultado['modulos'] as $modulo) {
                    $this->line("      • {$modulo['nombre']} ({$modulo['codigo']})");
                    if (!empty($modulo['hijos'])) {
                        foreach ($modulo['hijos'] as $hijo) {
                            $this->line("         └─ {$hijo['nombre']} ({$hijo['codigo']})");
                        }
                    }
                }
            }

            if (!empty($resultado['problemas'])) {
                $this->line("   ⚠️  Problemas detectados:");
                foreach ($resultado['problemas'] as $problema) {
                    $this->line("      • {$problema}");
                }
            }

            if ($resultado['error']) {
                $this->line("   ❌ Error: {$resultado['error']}");
            }

            // Mostrar roles y perfiles
            if ($resultado['roles_count'] > 0) {
                $this->line("   🎭 Roles:");
                foreach ($usuario->rolesCustom as $rol) {
                    $admin = $rol->es_admin ? ' (ADMIN)' : '';
                    $this->line("      • {$rol->nombre}{$admin} - Perfiles: {$rol->perfiles->count()}");
                }
            }
        } else {
            // Vista resumida
            $info = "Roles: {$resultado['roles_count']}, Módulos: {$resultado['modulos_count']}";
            if (!empty($resultado['problemas'])) {
                $info .= " - Problemas: " . count($resultado['problemas']);
            }
            $this->line("   {$info}");
        }
    }

    private function showSummary($conModulos, $sinModulos, $total)
    {
        $this->line("\n" . str_repeat('=', 80));
        $this->info('📈 RESUMEN FINAL:');
        
        $porcentajeConModulos = $total > 0 ? round(($conModulos / $total) * 100, 1) : 0;
        $porcentajeSinModulos = $total > 0 ? round(($sinModulos / $total) * 100, 1) : 0;

        $this->line("   ✅ Usuarios CON módulos en sidebar: {$conModulos} ({$porcentajeConModulos}%)");
        $this->line("   ❌ Usuarios SIN módulos en sidebar: {$sinModulos} ({$porcentajeSinModulos}%)");
        $this->line("   📊 Total usuarios validados: {$total}");

        if ($sinModulos > 0) {
            $this->line("\n💡 RECOMENDACIONES:");
            $this->line("   • Ejecutar: php artisan sidebar:fix-users (si existe)");
            $this->line("   • Revisar asignación de roles y perfiles");
            $this->line("   • Verificar módulos asignados a empresas");
            $this->line("   • Ejecutar: php setup_sidebar_data.php para datos de ejemplo");
        }
    }

    private function exportResults($resultados)
    {
        $archivo = $this->option('export');
        $contenido = "REPORTE DE VALIDACIÓN DE SIDEBAR - " . now()->format('Y-m-d H:i:s') . "\n";
        $contenido .= str_repeat('=', 80) . "\n\n";

        foreach ($resultados as $resultado) {
            $usuario = $resultado['usuario'];
            $status = $resultado['tiene_sidebar'] ? 'CON SIDEBAR' : 'SIN SIDEBAR';
            
            $contenido .= "{$usuario->name} ({$usuario->email}) - {$status}\n";
            $contenido .= "  Roles: {$resultado['roles_count']}\n";
            $contenido .= "  Empresas: {$resultado['empresas_count']}\n";
            $contenido .= "  Perfiles: {$resultado['perfiles_count']}\n";
            $contenido .= "  Módulos: {$resultado['modulos_count']}\n";
            
            if (!empty($resultado['problemas'])) {
                $contenido .= "  Problemas: " . implode(', ', $resultado['problemas']) . "\n";
            }
            
            $contenido .= "\n";
        }

        file_put_contents($archivo, $contenido);
        $this->info("📄 Resultados exportados a: {$archivo}");
    }
}