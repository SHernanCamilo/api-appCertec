<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Models\Empresa;
use Illuminate\Database\Seeder;
use App\Services\SidebarService;

class BiOdataLinksModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🔗 Configurando módulo BI-ODATA-LINKS...');

        // 1. Obtener o crear el módulo padre (BI-PARAMETROS)
        $moduloPadre = Modulo::where('codigo', 'BI-PARAMETROS')->first();

        if (!$moduloPadre) {
            $this->command->error('❌ No se encontró el módulo BI-PARAMETROS');
            return;
        }

        // 2. Crear o actualizar el módulo BI-ODATA-LINKS
        $moduloOdata = Modulo::updateOrCreate(
            ['codigo' => 'BI-ODATA-LINKS'],
            [
                'id_modulo_padre' => $moduloPadre->id,
                'nombre' => 'Generación OData',
                'descripcion' => 'Generación de URLs dinámicas y permisos de actualización desde Excel',
                'icono' => 'bi bi-link',
                'ruta' => '/inteligenciaNegocios/parametros/odata-links',
                'orden' => 1,
                'nivel' => 2,
                'estado' => 1,
            ]
        );

        $this->command->info("✅ Módulo BI-ODATA-LINKS listo (ID: {$moduloOdata->id})");

        // 3. Asignar el módulo a todas las empresas que ya tienen acceso a BI-PARAMETROS
        $empresas = Empresa::all();

        foreach ($empresas as $empresa) {
            // Verificar si la empresa tiene acceso al módulo padre
            $tieneAccesoPadre = ModuloEmpresa::where('id_modulo', $moduloPadre->id)
                ->where('id_empresa', $empresa->id)
                ->where('activo', 1)
                ->exists();

            if ($tieneAccesoPadre) {
                // Asignar módulo BI-ODATA-LINKS a la empresa
                ModuloEmpresa::updateOrCreate(
                    ['id_modulo' => $moduloOdata->id, 'id_empresa' => $empresa->id],
                    ['activo' => 1, 'hereda_hijos' => 0]
                );
                $this->command->info("  ✓ Asignado a empresa: {$empresa->nombre}");
            }
        }

        // 4. Crear o actualizar perfil para BI-ODATA-LINKS
        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'BI-ODATA-LINKS-ADMIN', 'id_modulo' => $moduloOdata->id],
            [
                'nombre' => 'Administrador de OData Links',
                'descripcion' => 'Permite gestionar enlaces OData y permisos de Excel',
                'puede_leer' => 1,
                'puede_crear' => 1,
                'puede_editar' => 1,
                'puede_eliminar' => 1,
                'estado' => 1,
            ]
        );

        $this->command->info("✅ Perfil '{$perfil->nombre}' listo (ID: {$perfil->id})");

        // 5. Invalidate cache del sidebar
        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command->info('🎉 Módulo BI-ODATA-LINKS configurado exitosamente!');
        $this->command->info('📝 Recuerda asignar este perfil a los roles de usuario correspondientes.');
    }
}
