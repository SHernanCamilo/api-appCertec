<?php

namespace Database\Seeders;

use App\Models\Modulo;
use Illuminate\Database\Seeder;
use App\Services\SidebarService;

class BiParametrosModulosSeeder extends Seeder
{
    /**
     * Actualizar rutas Angular para parámetros BI.
     * 
     * NOTA: Este seeder actualiza módulos existentes. Si los módulos no existen,
     * ejecutar primero: php artisan db:seed --class=BiModulosSeeder
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

        if (count($noEncontrados) > 0) {
            echo "\n⚠️  Módulos no encontrados (no se actualizaron):\n";
            foreach ($noEncontrados as $codigo) {
                echo "   - {$codigo}\n";
            }
            echo "\nEjecuta: php artisan db:seed --class=BiModulosSeeder\n";
        }

        if ($actualizados > 0) {
            app(SidebarService::class)->invalidateAllSidebarCache();
            echo "\n✅ {$actualizados} módulos actualizados. Caché del sidebar invalidado.\n";
        }
    }
}
