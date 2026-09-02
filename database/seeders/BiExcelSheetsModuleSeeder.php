<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Services\SidebarService;
use Illuminate\Database\Seeder;

/**
 * Registra en el menu el modulo "Excel Sheets" (workbooks guardados del visor BI).
 *
 * La pantalla ya existia en Angular (/inteligenciaNegocios/excelSheets) pero no
 * tenia fila en seg_modulos, asi que no aparecia en el sidebar y solo se llegaba
 * escribiendo la URL a mano.
 *
 * Datos que persiste esa pantalla (ya implementados en backend):
 *   - bi_workbooks        -> nombre, vistas incluidas, favorito, estado UI completo
 *   - bi_workbook_states  -> quick-state por vista (columnas ocultas, zoom, filtros)
 *
 * Ejecutar:
 *   php artisan db:seed --class=Database\\Seeders\\BiExcelSheetsModuleSeeder
 *
 * Despues hay que asignar el perfil 'BI-EXCELS-LECTURA' a los roles que deban
 * verlo (Configuracion > Roles > Perfiles). Los roles con es_admin lo ven solos.
 */
class BiExcelSheetsModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Configurando modulo BI-VISTAS-EXCELS...');

        // Se cuelga de "Fuentes Unicas de Informacion": los workbooks son
        // combinaciones de esas vistas.
        $padre = Modulo::where('codigo', 'BI-VISTAS')->first();

        if (!$padre) {
            $this->command?->error('No se encontro el modulo padre BI-VISTAS.');
            return;
        }

        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'BI-VISTAS-EXCELS'], // codigo <= 20 chars (seg_modulos.codigo)
            [
                'id_modulo_padre' => $padre->id,
                'nombre'      => 'Excel Sheets',
                'descripcion' => 'Workbooks guardados con hojas, filtros, formulas y tablas dinamicas',
                'icono'       => 'bi bi-file-earmark-excel',
                'ruta'        => '/inteligenciaNegocios/excelSheets',
                'orden'       => 10,
                'nivel'       => ($padre->nivel ?? 1) + 1,
                'estado'      => 1,
            ]
        );

        $this->command?->info("Modulo BI-VISTAS-EXCELS listo (ID: {$modulo->id})");

        // Acceso por empresa: se replica el de BI-VISTAS, no se inventa nada.
        foreach (Empresa::all() as $empresa) {
            $tieneAccesoPadre = ModuloEmpresa::where('id_modulo', $padre->id)
                ->where('id_empresa', $empresa->id)
                ->where('activo', 1)
                ->exists();

            if (!$tieneAccesoPadre) {
                continue;
            }

            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $modulo->id, 'id_empresa' => $empresa->id],
                ['activo' => 1, 'hereda_hijos' => 0]
            );

            $this->command?->info("  Asignado a empresa: {$empresa->nombre}");
        }

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'BI-EXCELS-LECTURA', 'id_modulo' => $modulo->id],
            [
                'nombre'         => 'Excel Sheets — Gestion de workbooks',
                'descripcion'    => 'Permite guardar, abrir y eliminar workbooks propios del visor BI',
                'puede_leer'     => 1,
                'puede_crear'    => 1,
                'puede_editar'   => 1,
                'puede_eliminar' => 1,
                'estado'         => 1,
            ]
        );

        $this->command?->info("Perfil '{$perfil->nombre}' listo (ID: {$perfil->id})");

        // Sin esto el sidebar sigue sirviendo la version cacheada y el modulo
        // nuevo no aparece hasta que expire el cache.
        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command?->info('Modulo BI-VISTAS-EXCELS configurado.');
        $this->command?->warn('Falta asignar el perfil BI-EXCELS-LECTURA a los roles correspondientes.');
    }
}
