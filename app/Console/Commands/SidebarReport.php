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

class SidebarReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sidebar:report 
                            {--format=table : Formato de salida (table, json, csv)}
                            {--output= : Archivo de salida}
                            {--include-inactive : Incluir usuarios inactivos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera un reporte completo del estado del sidebar dinámico';

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
        $this->info('📊 GENERADOR DE REPORTES DE SIDEBAR');
        $this->line(str_repeat('=', 80));

        $incluirInactivos = $this->option('include-inactive');
        $formato = $this->option('format');

        // Recopilar datos
        $datos = $this->recopilarDatos($incluirInactivos);

        // Generar reporte según formato
        switch ($formato) {
            case 'json':
                $this->generarReporteJSON($datos);
                break;
            case 'csv':
                $this->generarReporteCSV($datos);
                break;
            default:
                $this->generarReporteTabla($datos);
                break;
        }

        return 0;
    }

    private function recopilarDatos(bool $incluirInactivos): array
    {
        $this->info('🔍 Recopilando datos...');

        // Estadísticas generales
        $estadisticas = [
            'usuarios_activos' => User::where('estado', 1)->count(),
            'usuarios_inactivos' => User::where('estado', 0)->count(),
            'modulos_activos' => Modulo::where('estado', 1)->count(),
            'modulos_raiz' => Modulo::whereNull('id_modulo_padre')->where('estado', 1)->count(),
            'roles_activos' => Rol::where('estado', 1)->count(),
            'perfiles_activos' => Perfil::where('estado', 1)->count(),
            'empresas_activas' => Empresa::where('estado', 1)->count(),
            'asignaciones_modulo_empresa' => ModuloEmpresa::where('activo', 1)->count(),
        ];

        // Usuarios
        $query = User::with(['rolesCustom.perfiles.modulo', 'empresas']);
        if (!$incluirInactivos) {
            $query->where('estado', 1);
        }
        $usuarios = $query->get();

        $datosUsuarios = [];
        $usuariosConSidebar = 0;
        $usuariosSinSidebar = 0;

        foreach ($usuarios as $usuario) {
            $sidebar = [];
            $error = null;
            
            try {
                $sidebar = $this->sidebarService->getSidebarModules($usuario);
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }

            $perfiles = $usuario->rolesCustom->flatMap->perfiles;
            
            $datosUsuario = [
                'id' => $usuario->id,
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'estado' => $usuario->estado ? 'Activo' : 'Inactivo',
                'roles_count' => $usuario->rolesCustom->count(),
                'empresas_count' => $usuario->empresas->count(),
                'perfiles_count' => $perfiles->count(),
                'perfiles_lectura' => $perfiles->where('puede_leer', true)->count(),
                'modulos_sidebar' => count($sidebar),
                'tiene_sidebar' => count($sidebar) > 0,
                'modulos_nombres' => collect($sidebar)->pluck('nombre')->join(', '),
                'roles_nombres' => $usuario->rolesCustom->pluck('nombre')->join(', '),
                'empresas_nombres' => $usuario->empresas->pluck('nombre')->join(', '),
                'error' => $error
            ];

            $datosUsuarios[] = $datosUsuario;

            if ($datosUsuario['tiene_sidebar']) {
                $usuariosConSidebar++;
            } else {
                $usuariosSinSidebar++;
            }
        }

        // Módulos
        $modulos = Modulo::with(['padre', 'hijos'])->orderBy('nivel')->orderBy('orden')->get();
        $datosModulos = [];

        foreach ($modulos as $modulo) {
            $asignaciones = ModuloEmpresa::where('id_modulo', $modulo->id)->where('activo', 1)->count();
            $perfiles = Perfil::where('id_modulo', $modulo->id)->where('estado', 1)->count();
            
            $datosModulos[] = [
                'id' => $modulo->id,
                'nombre' => $modulo->nombre,
                'codigo' => $modulo->codigo,
                'nivel' => $modulo->nivel,
                'padre' => $modulo->padre?->nombre ?? 'Raíz',
                'hijos_count' => $modulo->hijos->count(),
                'estado' => $modulo->estado ? 'Activo' : 'Inactivo',
                'ruta' => $modulo->ruta ?? 'Sin ruta',
                'icono' => $modulo->icono ?? 'Sin ícono',
                'asignaciones_empresa' => $asignaciones,
                'perfiles_count' => $perfiles
            ];
        }

        // Roles y perfiles
        $roles = Rol::with(['perfiles.modulo'])->get();
        $datosRoles = [];

        foreach ($roles as $rol) {
            $usuarios_count = $rol->users()->count();
            
            $datosRoles[] = [
                'id' => $rol->id,
                'nombre' => $rol->nombre,
                'codigo' => $rol->codigo,
                'empresa' => $rol->empresa?->nombre ?? 'Global',
                'es_admin' => $rol->es_admin ? 'Sí' : 'No',
                'estado' => $rol->estado ? 'Activo' : 'Inactivo',
                'perfiles_count' => $rol->perfiles->count(),
                'usuarios_count' => $usuarios_count,
                'modulos' => $rol->perfiles->pluck('modulo.nombre')->filter()->unique()->join(', ')
            ];
        }

        return [
            'estadisticas' => $estadisticas,
            'resumen' => [
                'total_usuarios' => count($datosUsuarios),
                'usuarios_con_sidebar' => $usuariosConSidebar,
                'usuarios_sin_sidebar' => $usuariosSinSidebar,
                'porcentaje_con_sidebar' => count($datosUsuarios) > 0 ? round(($usuariosConSidebar / count($datosUsuarios)) * 100, 1) : 0
            ],
            'usuarios' => $datosUsuarios,
            'modulos' => $datosModulos,
            'roles' => $datosRoles,
            'fecha_generacion' => now()->format('Y-m-d H:i:s')
        ];
    }

    private function generarReporteTabla(array $datos)
    {
        // Estadísticas generales
        $this->info("\n📊 ESTADÍSTICAS GENERALES:");
        $headers = ['Métrica', 'Valor'];
        $rows = [];
        foreach ($datos['estadisticas'] as $metrica => $valor) {
            $rows[] = [str_replace('_', ' ', ucfirst($metrica)), $valor];
        }
        $this->table($headers, $rows);

        // Resumen de usuarios
        $this->info("\n👥 RESUMEN DE USUARIOS:");
        $resumen = $datos['resumen'];
        $this->line("   Total usuarios: {$resumen['total_usuarios']}");
        $this->line("   Con sidebar: {$resumen['usuarios_con_sidebar']} ({$resumen['porcentaje_con_sidebar']}%)");
        $this->line("   Sin sidebar: {$resumen['usuarios_sin_sidebar']}");

        // Tabla de usuarios
        $this->info("\n👤 DETALLE DE USUARIOS:");
        $headers = ['Nombre', 'Email', 'Estado', 'Roles', 'Módulos', 'Sidebar'];
        $rows = [];
        foreach ($datos['usuarios'] as $usuario) {
            $sidebar = $usuario['tiene_sidebar'] ? '✅' : '❌';
            $rows[] = [
                $usuario['nombre'],
                $usuario['email'],
                $usuario['estado'],
                $usuario['roles_count'],
                $usuario['modulos_sidebar'],
                $sidebar
            ];
        }
        $this->table($headers, $rows);

        // Tabla de módulos
        $this->info("\n📦 MÓDULOS:");
        $headers = ['Nombre', 'Código', 'Nivel', 'Estado', 'Asignaciones', 'Perfiles'];
        $rows = [];
        foreach ($datos['modulos'] as $modulo) {
            $rows[] = [
                $modulo['nombre'],
                $modulo['codigo'],
                $modulo['nivel'],
                $modulo['estado'],
                $modulo['asignaciones_empresa'],
                $modulo['perfiles_count']
            ];
        }
        $this->table($headers, $rows);

        // Tabla de roles
        $this->info("\n🎭 ROLES:");
        $headers = ['Nombre', 'Empresa', 'Admin', 'Perfiles', 'Usuarios'];
        $rows = [];
        foreach ($datos['roles'] as $rol) {
            $rows[] = [
                $rol['nombre'],
                $rol['empresa'],
                $rol['es_admin'],
                $rol['perfiles_count'],
                $rol['usuarios_count']
            ];
        }
        $this->table($headers, $rows);
    }

    private function generarReporteJSON(array $datos)
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if ($this->option('output')) {
            file_put_contents($this->option('output'), $json);
            $this->info("📄 Reporte JSON guardado en: " . $this->option('output'));
        } else {
            $this->line($json);
        }
    }

    private function generarReporteCSV(array $datos)
    {
        $archivo = $this->option('output') ?? 'sidebar_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        $handle = fopen($archivo, 'w');
        
        // Escribir usuarios
        fputcsv($handle, ['USUARIOS']);
        fputcsv($handle, ['Nombre', 'Email', 'Estado', 'Roles', 'Empresas', 'Perfiles', 'Módulos Sidebar', 'Tiene Sidebar']);
        
        foreach ($datos['usuarios'] as $usuario) {
            fputcsv($handle, [
                $usuario['nombre'],
                $usuario['email'],
                $usuario['estado'],
                $usuario['roles_count'],
                $usuario['empresas_count'],
                $usuario['perfiles_count'],
                $usuario['modulos_sidebar'],
                $usuario['tiene_sidebar'] ? 'Sí' : 'No'
            ]);
        }
        
        // Línea vacía
        fputcsv($handle, []);
        
        // Escribir módulos
        fputcsv($handle, ['MÓDULOS']);
        fputcsv($handle, ['Nombre', 'Código', 'Nivel', 'Estado', 'Asignaciones Empresa', 'Perfiles']);
        
        foreach ($datos['modulos'] as $modulo) {
            fputcsv($handle, [
                $modulo['nombre'],
                $modulo['codigo'],
                $modulo['nivel'],
                $modulo['estado'],
                $modulo['asignaciones_empresa'],
                $modulo['perfiles_count']
            ]);
        }
        
        fclose($handle);
        
        $this->info("📄 Reporte CSV guardado en: {$archivo}");
    }
}