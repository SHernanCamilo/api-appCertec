<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\SidebarService;
use Illuminate\Database\Seeder;

class MatrizObsComparadorModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Configurando módulo INV-MATRIX-COMPARADOR...');

        $padre = Modulo::where('codigo', 'INV-MATRIZ')->first();

        if (!$padre) {
            $this->command?->error('No se encontró el módulo padre INV-MATRIZ.');
            return;
        }

        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'INV-MATRIX-COMPARADOR'],
            [
                'id_modulo_padre' => $padre->id,
                'nombre' => 'Comparador Matriz Obsolescencia',
                'descripcion' => 'Compara un Excel de inventario contra los activos de la matriz de obsolescencia',
                'icono' => 'bi bi-arrow-left-right',
                'ruta' => '/inventario/matrizObsolescencia/comparadorMaObsolescencia',
                'orden' => 4,
                'nivel' => ($padre->nivel ?? 1) + 1,
                'estado' => 1,
            ]
        );

        $empresasConPadre = ModuloEmpresa::where('id_modulo', $padre->id)
            ->where('activo', 1)
            ->pluck('id_empresa')
            ->unique()
            ->filter();

        if ($empresasConPadre->isEmpty()) {
            $empresasConPadre = Empresa::query()->pluck('id');
        }

        foreach ($empresasConPadre as $idEmpresa) {
            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $modulo->id, 'id_empresa' => $idEmpresa],
                ['activo' => 1, 'hereda_hijos' => 0]
            );
        }

        $permisoVisible = Permiso::updateOrCreate(
            ['codigo' => 'inv-matriz-comparador-visible'],
            [
                'id_modulo' => $modulo->id,
                'nombre' => 'Visible Comparador Matriz',
                'descripcion' => 'Permite ver el comparador de matriz de obsolescencia en el menú',
                'tipo' => 'menu',
                'orden' => 0,
                'estado' => true,
            ]
        );

        $permisoComparar = Permiso::updateOrCreate(
            ['codigo' => 'comparar-matriz'],
            [
                'id_modulo' => $modulo->id,
                'nombre' => 'Comparar Excel vs BD',
                'descripcion' => 'Permite cargar un Excel y comparar activos contra la base de datos',
                'tipo' => 'boton',
                'orden' => 1,
                'estado' => true,
            ]
        );

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-MATRIX-COMPARADOR', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Comparador Matriz Obsolescencia',
                'descripcion' => 'Acceso al comparador Excel vs base de datos',
                'puede_leer' => 1,
                'puede_crear' => 0,
                'puede_editar' => 0,
                'puede_eliminar' => 0,
                'estado' => 1,
            ]
        );

        $perfil->permisos()->sync([$permisoVisible->id, $permisoComparar->id]);

        $roles = Rol::query()
            ->where('estado', 1)
            ->where(function ($query): void {
                $query->where('es_admin', 1)
                    ->orWhere('codigo', 'super-admin')
                    ->orWhere('nombre', 'like', '%Super Administrador%')
                    ->orWhereHas('perfiles.modulo', function ($q): void {
                        $q->whereIn('codigo', [
                            'INV-MATRIZ',
                            'INV-MATRIX-DAHSBOARD',
                            'INV-MATRIX-REPORTE',
                            'INV-MATRIX-CIERRE',
                        ]);
                    });
            })
            ->get();

        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
        }

        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command?->info("Módulo INV-MATRIX-COMPARADOR listo (ID: {$modulo->id}).");
        $this->command?->info('Cierra sesión y vuelve a entrar si el menú no aparece de inmediato.');
    }
}
