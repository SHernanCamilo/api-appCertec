<?php

namespace Database\Seeders;

use App\Models\Modulo;
use Illuminate\Database\Seeder;
use App\Services\SidebarService;

class BiVistasModulosSeeder extends Seeder
{
    /**
     * Rutas Angular para los submódulos de Fuentes Únicas de Información.
     */
    public function run(): void
    {
        $rutas = [
            'BI-VISTAS-REPORTE_AD' => '/inteligenciaNegocios/reportes-administrativos',
            'BI-VISTAS-REPORTE_AS' => '/inteligenciaNegocios/reportes-asistenciales',
            'BI-VISTAS-REPORTE_FI' => '/inteligenciaNegocios/reportes-financieros',
        ];

        foreach ($rutas as $codigo => $ruta) {
            Modulo::where('codigo', $codigo)->update(['ruta' => $ruta]);
        }

        app(SidebarService::class)->invalidateAllSidebarCache();
    }
}
