<?php

namespace Database\Seeders;

use App\Models\Modulo;
use Illuminate\Database\Seeder;
use App\Services\SidebarService;

class BiParametrosModulosSeeder extends Seeder
{
    /**
     * Rutas Angular para parámetros BI: esquemas y OData Links.
     */
    public function run(): void
    {
        // Actualizar ruta de esquemas
        Modulo::where('codigo', 'BI-PARAMETROS-ESQ')->update([
            'ruta' => '/inteligenciaNegocios/parametros/esquemas',
        ]);

        // Crear o actualizar módulo de OData Links
        $moduloPadre = Modulo::where('codigo', 'BI-PARAMETROS')->first();

        if ($moduloPadre) {
            Modulo::updateOrCreate(
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
        }

        app(SidebarService::class)->invalidateAllSidebarCache();
    }
}
