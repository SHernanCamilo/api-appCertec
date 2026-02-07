<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permiso;
use App\Models\Modulo;

class PermisosEmpresaSeeder extends Seeder
{
    /**
     * Seed de permisos para el módulo de Empresas
     */
    public function run(): void
    {
        echo "🔐 Creando permisos para el módulo de Empresas...\n\n";

        // Obtener el módulo "Maestro de Empresas"
        $moduloMaestroEmpresas = Modulo::where('codigo', 'ORG_EMP_MAESTRO')->first();

        if (!$moduloMaestroEmpresas) {
            echo "❌ Error: No se encontró el módulo 'ORG_EMP_MAESTRO'\n";
            echo "   Ejecuta primero: php artisan db:seed --class=ModulosSeeder\n";
            return;
        }

        echo "📦 Módulo encontrado: {$moduloMaestroEmpresas->nombre} (ID: {$moduloMaestroEmpresas->id})\n\n";

        // Definir permisos para el módulo de Empresas
        $permisos = [
            // BOTONES CRUD
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Crear Empresa',
                'codigo' => 'org-emp-crear',
                'descripcion' => 'Permite crear nuevas empresas en el sistema',
                'tipo' => 'boton',
                'icono' => 'plus-circle',
                'orden' => 1,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Editar Empresa',
                'codigo' => 'org-emp-editar',
                'descripcion' => 'Permite editar información de empresas existentes',
                'tipo' => 'boton',
                'icono' => 'pencil',
                'orden' => 2,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Eliminar Empresa',
                'codigo' => 'org-emp-eliminar',
                'descripcion' => 'Permite eliminar empresas del sistema',
                'tipo' => 'boton',
                'icono' => 'trash',
                'orden' => 3,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Activar/Desactivar Empresa',
                'codigo' => 'org-emp-toggle-estado',
                'descripcion' => 'Permite activar o desactivar empresas',
                'tipo' => 'boton',
                'icono' => 'toggle-on',
                'orden' => 4,
                'estado' => true
            ],

            // ACCIONES
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Ver Empresas',
                'codigo' => 'org-emp-ver',
                'descripcion' => 'Permite visualizar el listado de empresas',
                'tipo' => 'accion',
                'icono' => 'eye',
                'orden' => 5,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Ver Detalle Empresa',
                'codigo' => 'org-emp-ver-detalle',
                'descripcion' => 'Permite ver información detallada de una empresa',
                'tipo' => 'accion',
                'icono' => 'info-circle',
                'orden' => 6,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Exportar Empresas',
                'codigo' => 'org-emp-exportar',
                'descripcion' => 'Permite exportar el listado de empresas a Excel/PDF',
                'tipo' => 'accion',
                'icono' => 'download',
                'orden' => 7,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloMaestroEmpresas->id,
                'nombre' => 'Buscar Empresas',
                'codigo' => 'org-emp-buscar',
                'descripcion' => 'Permite buscar y filtrar empresas',
                'tipo' => 'accion',
                'icono' => 'search',
                'orden' => 8,
                'estado' => true
            ],
        ];

        // Crear permisos
        $creados = 0;
        $existentes = 0;

        foreach ($permisos as $permisoData) {
            // Verificar si ya existe
            $existe = Permiso::where('codigo', $permisoData['codigo'])->first();

            if ($existe) {
                echo "  ⚠️  Ya existe: {$permisoData['nombre']} ({$permisoData['codigo']})\n";
                $existentes++;
            } else {
                Permiso::create($permisoData);
                echo "  ✅ Creado: {$permisoData['nombre']} ({$permisoData['codigo']})\n";
                $creados++;
            }
        }

        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "📊 RESUMEN:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  ✅ Permisos creados:    {$creados}\n";
        echo "  ⚠️  Permisos existentes: {$existentes}\n";
        echo "  📦 Total procesados:    " . ($creados + $existentes) . "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        // Mostrar permisos creados por tipo
        $botones = Permiso::where('id_modulo', $moduloMaestroEmpresas->id)
            ->where('tipo', 'boton')
            ->count();
        
        $acciones = Permiso::where('id_modulo', $moduloMaestroEmpresas->id)
            ->where('tipo', 'accion')
            ->count();

        echo "📋 PERMISOS POR TIPO:\n";
        echo "  🔘 Botones:  {$botones}\n";
        echo "  ⚡ Acciones: {$acciones}\n\n";

        echo "✅ Proceso completado exitosamente!\n";
    }
}
