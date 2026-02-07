<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Perfil;
use App\Models\Modulo;
use App\Models\Permiso;

class PerfilesSeeder extends Seeder
{
    /**
     * Seed de perfiles del sistema
     */
    public function run(): void
    {
        echo "👤 Creando perfiles del sistema...\n\n";

        // Obtener módulos
        $moduloMaestroEmpresas = Modulo::where('codigo', 'ORG_EMP_MAESTRO')->first();
        $moduloSucursales = Modulo::where('codigo', 'ORG_SUC')->first();
        $moduloSedes = Modulo::where('codigo', 'ORG_SED')->first();

        if (!$moduloMaestroEmpresas) {
            echo "❌ Error: Módulos no encontrados. Ejecuta primero ModulosSeeder\n";
            return;
        }

        // Obtener permisos del módulo de empresas
        $permisosEmpresas = Permiso::where('id_modulo', $moduloMaestroEmpresas->id)
            ->pluck('codigo')
            ->toArray();

        // ========================================
        // PERFILES PARA MÓDULO DE EMPRESAS
        // ========================================

        // 1. Administrador de Empresas (Acceso Total)
        $adminEmpresas = Perfil::create([
            'nombre' => 'Administrador de Empresas',
            'codigo' => 'admin-empresas',
            'id_modulo' => $moduloMaestroEmpresas->id,
            'descripcion' => 'Acceso completo al módulo de empresas',
            'puede_crear' => true,
            'puede_leer' => true,
            'puede_editar' => true,
            'puede_eliminar' => true,
            'permisos_extra' => $permisosEmpresas,
            'estado' => true
        ]);
        echo "  ✅ Creado: {$adminEmpresas->nombre}\n";

        // 2. Editor de Empresas (Sin eliminar)
        $editorEmpresas = Perfil::create([
            'nombre' => 'Editor de Empresas',
            'codigo' => 'editor-empresas',
            'id_modulo' => $moduloMaestroEmpresas->id,
            'descripcion' => 'Puede crear y editar empresas, pero no eliminar',
            'puede_crear' => true,
            'puede_leer' => true,
            'puede_editar' => true,
            'puede_eliminar' => false,
            'permisos_extra' => array_filter($permisosEmpresas, function($codigo) {
                return $codigo !== 'org-emp-eliminar';
            }),
            'estado' => true
        ]);
        echo "  ✅ Creado: {$editorEmpresas->nombre}\n";

        // 3. Consultor de Empresas (Solo lectura)
        $consultorEmpresas = Perfil::create([
            'nombre' => 'Consultor de Empresas',
            'codigo' => 'consultor-empresas',
            'id_modulo' => $moduloMaestroEmpresas->id,
            'descripcion' => 'Solo puede ver información de empresas',
            'puede_crear' => false,
            'puede_leer' => true,
            'puede_editar' => false,
            'puede_eliminar' => false,
            'permisos_extra' => ['org-emp-ver', 'org-emp-ver-detalle', 'org-emp-buscar', 'org-emp-exportar'],
            'estado' => true
        ]);
        echo "  ✅ Creado: {$consultorEmpresas->nombre}\n";

        // 4. Operador de Empresas (Crear y editar básico)
        $operadorEmpresas = Perfil::create([
            'nombre' => 'Operador de Empresas',
            'codigo' => 'operador-empresas',
            'id_modulo' => $moduloMaestroEmpresas->id,
            'descripcion' => 'Puede crear y editar empresas básicas',
            'puede_crear' => true,
            'puede_leer' => true,
            'puede_editar' => true,
            'puede_eliminar' => false,
            'permisos_extra' => ['org-emp-crear', 'org-emp-editar', 'org-emp-ver', 'org-emp-buscar'],
            'estado' => true
        ]);
        echo "  ✅ Creado: {$operadorEmpresas->nombre}\n";

        echo "\n";

        // ========================================
        // PERFILES PARA MÓDULO DE SUCURSALES
        // ========================================

        if ($moduloSucursales) {
            // 1. Administrador de Sucursales
            $adminSucursales = Perfil::create([
                'nombre' => 'Administrador de Sucursales',
                'codigo' => 'admin-sucursales',
                'id_modulo' => $moduloSucursales->id,
                'descripcion' => 'Acceso completo al módulo de sucursales',
                'puede_crear' => true,
                'puede_leer' => true,
                'puede_editar' => true,
                'puede_eliminar' => true,
                'permisos_extra' => [],
                'estado' => true
            ]);
            echo "  ✅ Creado: {$adminSucursales->nombre}\n";

            // 2. Consultor de Sucursales
            $consultorSucursales = Perfil::create([
                'nombre' => 'Consultor de Sucursales',
                'codigo' => 'consultor-sucursales',
                'id_modulo' => $moduloSucursales->id,
                'descripcion' => 'Solo puede ver información de sucursales',
                'puede_crear' => false,
                'puede_leer' => true,
                'puede_editar' => false,
                'puede_eliminar' => false,
                'permisos_extra' => [],
                'estado' => true
            ]);
            echo "  ✅ Creado: {$consultorSucursales->nombre}\n";
        }

        echo "\n";

        // ========================================
        // PERFILES PARA MÓDULO DE SEDES
        // ========================================

        if ($moduloSedes) {
            // 1. Administrador de Sedes
            $adminSedes = Perfil::create([
                'nombre' => 'Administrador de Sedes',
                'codigo' => 'admin-sedes',
                'id_modulo' => $moduloSedes->id,
                'descripcion' => 'Acceso completo al módulo de sedes',
                'puede_crear' => true,
                'puede_leer' => true,
                'puede_editar' => true,
                'puede_eliminar' => true,
                'permisos_extra' => [],
                'estado' => true
            ]);
            echo "  ✅ Creado: {$adminSedes->nombre}\n";

            // 2. Consultor de Sedes
            $consultorSedes = Perfil::create([
                'nombre' => 'Consultor de Sedes',
                'codigo' => 'consultor-sedes',
                'id_modulo' => $moduloSedes->id,
                'descripcion' => 'Solo puede ver información de sedes',
                'puede_crear' => false,
                'puede_leer' => true,
                'puede_editar' => false,
                'puede_eliminar' => false,
                'permisos_extra' => [],
                'estado' => true
            ]);
            echo "  ✅ Creado: {$consultorSedes->nombre}\n";
        }

        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "📊 RESUMEN:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        $totalPerfiles = Perfil::count();
        $perfilesActivos = Perfil::activos()->count();
        $perfilesPorModulo = Perfil::select('id_modulo')
            ->groupBy('id_modulo')
            ->get()
            ->count();
        
        echo "  📦 Total de perfiles:        {$totalPerfiles}\n";
        echo "  ✅ Perfiles activos:         {$perfilesActivos}\n";
        echo "  📂 Módulos con perfiles:     {$perfilesPorModulo}\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        echo "✅ Perfiles creados exitosamente!\n\n";
        
        echo "💡 NOTA: Los perfiles están creados pero sin asignar a roles.\n";
        echo "   Usa el módulo de Roles para asignar perfiles a cada rol.\n";
    }
}
