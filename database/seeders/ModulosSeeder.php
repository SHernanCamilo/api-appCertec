<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Empresa;

class ModulosSeeder extends Seeder
{
    public function run(): void
    {
        // 1. MÓDULO RAÍZ: ORGANIZACIÓN
        $organizacion = Modulo::create([
            'id_modulo_padre' => null,
            'nombre' => 'Organización',
            'codigo' => 'ORG',
            'descripcion' => 'Gestión de la estructura organizacional',
            'icono' => 'building',
            'ruta' => '/organizacion',
            'orden' => 1,
            'nivel' => 0,
            'estado' => 1
        ]);

        // 1.1 HIJO: EMPRESAS
        $empresas = Modulo::create([
            'id_modulo_padre' => $organizacion->id,
            'nombre' => 'Empresas',
            'codigo' => 'ORG_EMP',
            'descripcion' => 'Gestión de empresas del grupo',
            'icono' => 'briefcase',
            'ruta' => '/organizacion/empresas',
            'orden' => 1,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 1.1.1 NIETO: MAESTRO DE EMPRESAS
        Modulo::create([
            'id_modulo_padre' => $empresas->id,
            'nombre' => 'Maestro de Empresas',
            'codigo' => 'ORG_EMP_MAESTRO',
            'descripcion' => 'Configuración y datos maestros de empresas',
            'icono' => 'database',
            'ruta' => '/organizacion/empresas/maestro',
            'orden' => 1,
            'nivel' => 2,
            'estado' => 1
        ]);

        // 1.2 HIJO: SUCURSALES
        $sucursales = Modulo::create([
            'id_modulo_padre' => $organizacion->id,
            'nombre' => 'Sucursales',
            'codigo' => 'ORG_SUC',
            'descripcion' => 'Gestión de sucursales',
            'icono' => 'map-marker',
            'ruta' => '/organizacion/sucursales',
            'orden' => 2,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 1.3 HIJO: SEDES
        $sedes = Modulo::create([
            'id_modulo_padre' => $organizacion->id,
            'nombre' => 'Sedes',
            'codigo' => 'ORG_SED',
            'descripcion' => 'Gestión de sedes y ubicaciones',
            'icono' => 'location',
            'ruta' => '/organizacion/sedes',
            'orden' => 3,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 2. MÓDULO RAÍZ: SEGURIDAD
        $seguridad = Modulo::create([
            'id_modulo_padre' => null,
            'nombre' => 'Seguridad',
            'codigo' => 'SEG',
            'descripcion' => 'Gestión de usuarios, roles y permisos',
            'icono' => 'shield',
            'ruta' => '/seguridad',
            'orden' => 2,
            'nivel' => 0,
            'estado' => 1
        ]);

        // 2.1 HIJO: USUARIOS
        Modulo::create([
            'id_modulo_padre' => $seguridad->id,
            'nombre' => 'Usuarios',
            'codigo' => 'SEG_USR',
            'descripcion' => 'Gestión de usuarios del sistema',
            'icono' => 'users',
            'ruta' => '/seguridad/usuarios',
            'orden' => 1,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 2.2 HIJO: ROLES
        Modulo::create([
            'id_modulo_padre' => $seguridad->id,
            'nombre' => 'Roles',
            'codigo' => 'SEG_ROL',
            'descripcion' => 'Gestión de roles y perfiles',
            'icono' => 'user-tag',
            'ruta' => '/seguridad/roles',
            'orden' => 2,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 2.3 HIJO: PERMISOS
        Modulo::create([
            'id_modulo_padre' => $seguridad->id,
            'nombre' => 'Permisos',
            'codigo' => 'SEG_PER',
            'descripcion' => 'Gestión de permisos del sistema',
            'icono' => 'key',
            'ruta' => '/seguridad/permisos',
            'orden' => 3,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 3. MÓDULO RAÍZ: CRM
        $crm = Modulo::create([
            'id_modulo_padre' => null,
            'nombre' => 'CRM',
            'codigo' => 'CRM',
            'descripcion' => 'Gestión de relaciones con clientes',
            'icono' => 'users-cog',
            'ruta' => '/crm',
            'orden' => 3,
            'nivel' => 0,
            'estado' => 1
        ]);

        // 3.1 HIJO: CLIENTES
        Modulo::create([
            'id_modulo_padre' => $crm->id,
            'nombre' => 'Clientes',
            'codigo' => 'CRM_CLI',
            'descripcion' => 'Gestión de clientes',
            'icono' => 'user-circle',
            'ruta' => '/crm/clientes',
            'orden' => 1,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 3.2 HIJO: OPORTUNIDADES
        Modulo::create([
            'id_modulo_padre' => $crm->id,
            'nombre' => 'Oportunidades',
            'codigo' => 'CRM_OPO',
            'descripcion' => 'Gestión de oportunidades de venta',
            'icono' => 'chart-line',
            'ruta' => '/crm/oportunidades',
            'orden' => 2,
            'nivel' => 1,
            'estado' => 1
        ]);

        echo "✅ Módulos creados exitosamente!\n\n";

        // ASIGNAR MÓDULOS A EMPRESAS
        $empresasDB = Empresa::all();

        if ($empresasDB->count() > 0) {
            echo "📋 Asignando módulos a empresas...\n";

            foreach ($empresasDB as $empresa) {
                // Asignar módulo ORGANIZACIÓN (con herencia de hijos)
                ModuloEmpresa::asignarModuloAEmpresa($organizacion->id, $empresa->id, true);
                echo "  ✓ {$empresa->nombre}: Organización (con submódulos)\n";

                // Asignar módulo SEGURIDAD (con herencia de hijos)
                ModuloEmpresa::asignarModuloAEmpresa($seguridad->id, $empresa->id, true);
                echo "  ✓ {$empresa->nombre}: Seguridad (con submódulos)\n";

                // Asignar módulo CRM solo a la primera empresa (ejemplo)
                if ($empresa->id === 1) {
                    ModuloEmpresa::asignarModuloAEmpresa($crm->id, $empresa->id, true);
                    echo "  ✓ {$empresa->nombre}: CRM (con submódulos)\n";
                }
            }

            echo "\n✅ Módulos asignados a empresas exitosamente!\n";
        }
    }
}
