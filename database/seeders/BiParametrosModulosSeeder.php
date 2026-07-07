<?php

namespace Database\Seeders;

use App\Models\Modulo;
use Illuminate\Database\Seeder;
use App\Services\SidebarService;

class BiParametrosModulosSeeder extends Seeder
{
    /**
     * Ruta Angular para parámetros de esquemas BI.
     */
    public function run(): void
    {
        Modulo::where('codigo', 'BI-PARAM-ESQUEMAS')->update([
            'ruta' => '/inteligenciaNegocios/parametros/esquemas',
        ]);

        app(SidebarService::class)->invalidateAllSidebarCache();
    }
}
