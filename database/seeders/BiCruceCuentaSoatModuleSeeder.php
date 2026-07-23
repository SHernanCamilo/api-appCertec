<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Services\SidebarService;
use Illuminate\Database\Seeder;

class BiCruceCuentaSoatModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Configurando módulo BI-FORM-CRUCE-SOAT...');

        $moduloPadre = Modulo::where('codigo', 'BI')->first()
            ?? Modulo::where('ruta', '/inteligenciaNegocios')->whereNull('id_modulo_padre')->first()
            ?? Modulo::where('codigo', 'BI-VISTAS')->first();

        if (!$moduloPadre) {
            $this->command?->error('No se encontró el módulo padre BI / inteligenciaNegocios.');
            return;
        }

        $formularios = Modulo::updateOrCreate(
            ['codigo' => 'BI-FORMULARIOS'],
            [
                'id_modulo_padre' => $moduloPadre->id,
                'nombre' => 'Formularios',
                'descripcion' => 'Formularios de consulta sobre vistas de negocio',
                'icono' => 'bi bi-ui-checks',
                'ruta' => null,
                'orden' => 40,
                'nivel' => ($moduloPadre->nivel ?? 0) + 1,
                'estado' => 1,
            ]
        );

        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'BI-FORM-CRUCE-SOAT'],
            [
                'id_modulo_padre' => $formularios->id,
                'nombre' => 'Cruce cuenta SOAT',
                // Vista nacional: listada en config/bi_fabric.php (solo_esquema, sin filtro sede)
                'descripcion' => 'Consulta facturación SOAT por cédula (fr.VW_Billing_Facturacion_Soat)',
                'icono' => 'bi bi-card-heading',
                'ruta' => '/inteligenciaNegocios/formularios/cruceCuentaSoat',
                'orden' => 1,
                'nivel' => ($formularios->nivel ?? 1) + 1,
                'estado' => 1,
            ]
        );

        $this->command?->info("Módulo BI-FORM-CRUCE-SOAT listo (ID: {$modulo->id})");

        $empresas = Empresa::all();

        foreach ($empresas as $empresa) {
            $tieneAccesoPadre = ModuloEmpresa::where('id_modulo', $moduloPadre->id)
                ->where('id_empresa', $empresa->id)
                ->where('activo', 1)
                ->exists();

            if (!$tieneAccesoPadre) {
                continue;
            }

            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $formularios->id, 'id_empresa' => $empresa->id],
                ['activo' => 1, 'hereda_hijos' => 1]
            );

            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $modulo->id, 'id_empresa' => $empresa->id],
                ['activo' => 1, 'hereda_hijos' => 0]
            );

            $this->command?->info("  Asignado a empresa: {$empresa->nombre}");
        }

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'BI-FORM-CRUCE-SOAT-LECTURA', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Consulta Cruce cuenta SOAT',
                'descripcion' => 'Permite consultar facturación SOAT por cédula',
                'puede_leer' => 1,
                'puede_crear' => 0,
                'puede_editar' => 0,
                'puede_eliminar' => 0,
                'estado' => 1,
            ]
        );

        $this->command?->info("Perfil '{$perfil->nombre}' listo (ID: {$perfil->id})");

        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command?->info('Módulo BI-FORM-CRUCE-SOAT configurado.');
        $this->command?->info('Recuerda asignar el perfil a los roles correspondientes.');
    }
}
