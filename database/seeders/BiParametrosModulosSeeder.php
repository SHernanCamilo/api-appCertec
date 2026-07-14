<?php

namespace Database\Seeders;

use App\Models\Modulo;
use Illuminate\Database\Seeder;
use App\Services\SidebarService;

class BiParametrosModulosSeeder extends Seeder
{
    /**
     * Actualizar rutas Angular para parámetros BI y crear módulo OData Links.
     * 
     * NOTA: Este seeder actualiza módulos existentes y crea el módulo BI-ODATA-LINKS.
     * Si los módulos no existen, ejecutar primero: php artisan db:seed --class=BiModulosSeeder
     */
    public function run(): void
    {
        $rutas = [
            'BI-PARAM-ESQUEMAS'   => '/inteligenciaNegocios/parametros/esquemas',
            'BI-PARAMETROS-ESQ'   => '/inteligenciaNegocios/parametros/esquemas',
            'BI-PARAM-USUARIOS'   => '/inteligenciaNegocios/parametros/usuariosBI',
            'BI-PARAMETROS-USU'   => '/inteligenciaNegocios/parametros/usuariosBI',
            'BI-PARAMETROS-USR'   => '/inteligenciaNegocios/parametros/usuariosBI',
        ];

        $actualizados = 0;
        $noEncontrados = [];

        foreach ($rutas as $codigo => $ruta) {
            $updated = Modulo::where('codigo', $codigo)->update(['ruta' => $ruta]);
            
            if ($updated > 0) {
                $actualizados++;
                echo "✅ Actualizado: {$codigo} -> {$ruta}\n";
            } else {
                $noEncontrados[] = $codigo;
            }
        }

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
            echo "✅ Módulo BI-ODATA-LINKS creado/actualizado\n";
        }

        if (count($noEncontrados) > 0) {
            echo "\n⚠️  Módulos no encontrados (no se actualizaron):\n";
            foreach ($noEncontrados as $codigo) {
                echo "   - {$codigo}\n";
            }
            echo "\nEjecuta: php artisan db:seed --class=BiModulosSeeder\n";
        }

        app(SidebarService::class)->invalidateAllSidebarCache();
        echo "\n✅ Caché del sidebar invalidado.\n";
    }
}
