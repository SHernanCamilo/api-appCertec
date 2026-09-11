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
        $this->command?->info('Configurando módulo INV-MATRIX-COMPARAR...');

        $padre = Modulo::where('codigo', 'INV-MATRIZ')->first();

        if (!$padre) {
            $this->command?->error('No se encontró el módulo padre INV-MATRIZ.');
            return;
        }

        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'INV-MATRIX-COMPARAR'],
            [
                'id_modulo_padre' => $padre->id,
                'nombre' => 'Comparar',
                'descripcion' => 'Compara el Excel histórico de la matriz contra los activos actuales',
                'icono' => 'bi bi-arrow-left-right',
                'ruta' => '/inventario/matrizObsolescencia/comparar',
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
            ['codigo' => 'INV-MATRIX-COMPARAR', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Comparar Matriz de Obsolescencia',
                'descripcion' => 'Acceso a comparar el Excel histórico contra la matriz',
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
                    ->orWhere('codigo', 'like', '%super%')
                    ->orWhere('nombre', 'like', '%Super Administrador%')
                    ->orWhere('nombre', 'like', '%Superadmin%');
            })
            ->get();

        $rolesMatriz = Rol::query()
            ->where('estado', 1)
            ->whereHas('perfiles.modulo', function ($q): void {
                $q->whereIn('codigo', [
                    'INV-MATRIZ',
                    'INV-MATRIX-DAHSBOARD',
                    'INV-MATRIX-REPORTE',
                    'INV-MATRIX-CIERRE',
                ]);
            })
            ->get();

        $roles = $roles->merge($rolesMatriz)->unique('id');

        if ($roles->isEmpty()) {
            $this->command?->error('No se encontró el rol Superadmin para asignar el perfil.');
            return;
        }

        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
            $this->command?->info("  Perfil asignado a: {$rol->nombre} ({$rol->codigo})");
        }

        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command?->info("Módulo INV-MATRIX-COMPARAR listo (ID: {$modulo->id}).");
        $this->command?->info("Permisos: {$permisoVisible->codigo}, {$permisoComparar->codigo}");
        $this->command?->info('Cierra sesión y vuelve a entrar si el menú no aparece de inmediato.');
    }
}
