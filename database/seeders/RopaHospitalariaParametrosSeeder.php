<?php

namespace Database\Seeders;

use App\Models\Config\ConfigUnidadFuncional;
use App\Models\Empresa;
use App\Models\Inventory\Ropa\RopaConstante;
use App\Models\Inventory\Ropa\RopaPrenda;
use App\Models\Inventory\Ropa\RopaUnidadCupo;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\SidebarService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Menú Inventario > Ropa hospitalaria > Parámetros y catálogo inicial.
 *
 * El catálogo no pisa lo que ya se editó: si la prenda, el cupo o la
 * constante vigente ya existen, se dejan como están.
 */
class RopaHospitalariaParametrosSeeder extends Seeder
{
    public function run(): void
    {
        $inventario = Modulo::query()->where('codigo', 'INV')->first();
        if (!$inventario) {
            throw new RuntimeException('No existe el módulo INV.');
        }

        $grupo = Modulo::updateOrCreate(
            ['codigo' => 'INV-ROPA'],
            [
                'id_modulo_padre' => $inventario->id,
                'nombre' => 'Ropa hospitalaria',
                'descripcion' => 'Inventario de dotación de ropa por unidad',
                'icono' => 'bi-hospital',
                'ruta' => '/inventario/ropaHospitalaria',
                'orden' => 3,
                'nivel' => 1,
                'estado' => 1,
            ]
        );

        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'INV-ROPA-PARAM'],
            [
                'id_modulo_padre' => $grupo->id,
                'nombre' => 'Parámetros',
                'descripcion' => 'Prendas, cupos por unidad y constantes de dotación',
                'icono' => 'bi-sliders',
                'ruta' => '/inventario/ropaHospitalaria/parametros',
                'orden' => 1,
                'nivel' => 2,
                'estado' => 1,
            ]
        );

        $empresas = ModuloEmpresa::query()
            ->where('id_modulo', $inventario->id)
            ->where('activo', 1)
            ->pluck('id_empresa');

        if ($empresas->isEmpty()) {
            $empresas = Empresa::query()->pluck('id');
        }

        foreach ([$grupo->id, $modulo->id] as $idModulo) {
            foreach ($empresas as $idEmpresa) {
                ModuloEmpresa::updateOrCreate(
                    ['id_modulo' => $idModulo, 'id_empresa' => $idEmpresa],
                    ['activo' => 1, 'hereda_hijos' => 0]
                );
            }
        }

        $permiso = Permiso::updateOrCreate(
            ['codigo' => 'inv-ropa-param-visible'],
            [
                'id_modulo' => $modulo->id,
                'nombre' => 'Visible parámetros de ropa',
                'descripcion' => 'Muestra Parámetros de ropa hospitalaria en el menú',
                'tipo' => 'menu',
                'orden' => 0,
                'estado' => true,
            ]
        );

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-ROPA-PARAM', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Parámetros de ropa hospitalaria',
                'descripcion' => 'Administra prendas, cupos y constantes',
                'puede_leer' => 1,
                'puede_crear' => 1,
                'puede_editar' => 1,
                'puede_eliminar' => 1,
                'estado' => 1,
            ]
        );
        $perfil->permisos()->sync([$permiso->id]);

        $roles = Rol::query()
            ->where('estado', 1)
            ->whereIn('codigo', ['admin-sedes', 'administrador'])
            ->get();

        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
            $this->command?->info("Perfil asignado a {$rol->nombre}");
        }

        $this->menuToma($grupo);
        $this->menuReportePropio($grupo);
        $this->permisoConsultaSede();
        $this->sembrarCatalogo();

        app(SidebarService::class)->invalidateAllSidebarCache();
        $this->command?->info('Módulo INV-ROPA-PARAM listo. Recargue la sesión para ver el menú.');
    }

    private function menuToma(Modulo $grupo): void
    {
        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'INV-ROPA-TOMA'],
            [
                'id_modulo_padre' => $grupo->id,
                'nombre' => 'Inventario',
                'descripcion' => 'Toma del conteo físico de ropa por unidad y periodo',
                'icono' => 'bi-clipboard-check',
                'ruta' => '/inventario/ropaHospitalaria/inventario',
                'orden' => 0,
                'nivel' => 2,
                'estado' => 1,
            ]
        );

        $empresas = ModuloEmpresa::query()
            ->where('id_modulo', $grupo->id)
            ->where('activo', 1)
            ->pluck('id_empresa');

        foreach ($empresas as $idEmpresa) {
            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $modulo->id, 'id_empresa' => $idEmpresa],
                ['activo' => 1, 'hereda_hijos' => 0]
            );
        }

        $permiso = Permiso::updateOrCreate(
            ['codigo' => 'inv-ropa-toma-visible'],
            [
                'id_modulo' => $modulo->id,
                'nombre' => 'Visible inventario de ropa',
                'descripcion' => 'Muestra la toma de inventario de ropa en el menú',
                'tipo' => 'menu',
                'orden' => 0,
                'estado' => true,
            ]
        );

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-ROPA-TOMA', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Tomar inventario de ropa',
                'descripcion' => 'Abre y cierra el conteo físico',
                'puede_leer' => 1,
                'puede_crear' => 1,
                'puede_editar' => 1,
                'puede_eliminar' => 1,
                'estado' => 1,
            ]
        );
        $perfil->permisos()->sync([$permiso->id]);

        $super = Perfil::query()->where('codigo', 'super-admin')->first();
        $super?->permisos()->syncWithoutDetaching([$permiso->id]);

        $roles = Rol::query()
            ->where('estado', 1)
            ->whereIn('codigo', ['admin-sedes', 'administrador'])
            ->get();
        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
        }
    }

    private function menuReportePropio(Modulo $grupo): void
    {
        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'INV-ROPA-REP'],
            [
                'id_modulo_padre' => $grupo->id,
                'nombre' => 'Reportes',
                'descripcion' => 'Inventarios de ropa que el usuario cerró',
                'icono' => 'bi-file-earmark-bar-graph',
                'ruta' => '/inventario/ropaHospitalaria/reporte',
                'orden' => 2,
                'nivel' => 2,
                'estado' => 1,
            ]
        );

        $empresas = ModuloEmpresa::query()
            ->where('id_modulo', $grupo->id)
            ->where('activo', 1)
            ->pluck('id_empresa');

        foreach ($empresas as $idEmpresa) {
            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $modulo->id, 'id_empresa' => $idEmpresa],
                ['activo' => 1, 'hereda_hijos' => 0]
            );
        }

        $permiso = Permiso::updateOrCreate(
            ['codigo' => 'inv-ropa-rep-visible'],
            [
                'id_modulo' => $modulo->id,
                'nombre' => 'Visible reportes de ropa',
                'descripcion' => 'Muestra el reporte de los inventarios de ropa del usuario',
                'tipo' => 'menu',
                'orden' => 0,
                'estado' => true,
            ]
        );

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-ROPA-REP', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Reportes de ropa',
                'descripcion' => 'Consulta los inventarios de ropa que cerró',
                'puede_leer' => 1,
                'puede_crear' => 0,
                'puede_editar' => 0,
                'puede_eliminar' => 0,
                'estado' => 1,
            ]
        );
        $perfil->permisos()->sync([$permiso->id]);

        $super = Perfil::query()->where('codigo', 'super-admin')->first();
        $super?->permisos()->syncWithoutDetaching([$permiso->id]);

        $roles = Rol::query()
            ->where('estado', 1)
            ->whereIn('codigo', ['admin-sedes', 'administrador'])
            ->get();
        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
        }
    }

    private function permisoConsultaSede(): void
    {
        $modulo = Modulo::query()->where('codigo', 'INV-ROPA-REP')->first();
        if (!$modulo) {
            return;
        }

        $permiso = Permiso::updateOrCreate(
            ['codigo' => 'inv-ropa-consulta-sede'],
            [
                'id_modulo' => $modulo->id,
                'nombre' => 'Consulta por Sede',
                'descripcion' => 'En el reporte propio ve los inventarios cerrados de todos los usuarios de la sede',
                'tipo' => 'accion',
                'orden' => 1,
                'estado' => true,
            ]
        );

        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-ROPA-SEDE', 'id_modulo' => $modulo->id],
            [
                'nombre' => 'Consulta por Sede',
                'descripcion' => 'Amplía el reporte propio a todos los inventarios de la sede',
                'puede_leer' => 0,
                'puede_crear' => 0,
                'puede_editar' => 0,
                'puede_eliminar' => 0,
                'estado' => 1,
            ]
        );
        $perfil->permisos()->syncWithoutDetaching([$permiso->id]);

        $super = Perfil::query()->where('codigo', 'super-admin')->first();
        $super?->permisos()->syncWithoutDetaching([$permiso->id]);

        $rol = Rol::query()->where('estado', 1)->where('codigo', 'admin-sedes')->first();
        $rol?->perfiles()->syncWithoutDetaching([$perfil->id]);
    }

    private function sembrarCatalogo(): void
    {
        $ruta = database_path('seeders/data/ropa_hospitalaria_catalogo.json');
        if (!is_file($ruta)) {
            throw new RuntimeException("No está el catálogo {$ruta}");
        }

        $catalogo = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);

        $prendasNuevas = 0;
        $prendasPorCodigo = [];
        foreach ($catalogo['prendas'] as $fila) {
            $codigo = trim((string) ($fila['codigo'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            $prenda = RopaPrenda::query()->where('codigo', $codigo)->first();
            if (!$prenda) {
                $prenda = RopaPrenda::create([
                    'codigo' => $codigo,
                    'nombre' => $fila['nombre'],
                    'regla' => $fila['regla'],
                    'estado' => (int) $fila['estado'],
                ]);
                $prendasNuevas++;
            }
            $prendasPorCodigo[mb_strtoupper($codigo, 'UTF-8')] = $prenda;
        }

        $indice = $this->indiceUnidades();
        $cuposNuevos = 0;
        $unidadesFaltantes = [];
        foreach ($catalogo['unidades'] as $fila) {
            $codigo = mb_strtoupper(trim((string) $fila['codigo']), 'UTF-8');
            $unidad = $indice[$codigo] ?? null;
            if (!$unidad) {
                $unidadesFaltantes[] = $fila['codigo'] . ' ' . $fila['nombre'];
                continue;
            }
            $existe = RopaUnidadCupo::query()->where('id_unidad_funcional', $unidad->id)->exists();
            if ($existe) {
                continue;
            }
            RopaUnidadCupo::create([
                'id_unidad_funcional' => $unidad->id,
                'camas' => (int) $fila['camas'],
                'camas_medico' => (int) $fila['camas_medico'],
                'personal' => (int) $fila['personal'],
                'aplica_estar_medico' => (bool) $fila['aplica_estar_medico'],
                'aplica_personal' => (bool) $fila['aplica_personal'],
            ]);
            $cuposNuevos++;
        }

        $constantesNuevas = 0;
        $constantesSinPrenda = 0;
        $constantesSinUnidad = 0;
        foreach ($catalogo['constantes'] as $fila) {
            $prenda = $prendasPorCodigo[mb_strtoupper(trim((string) $fila['prenda_codigo']), 'UTF-8')] ?? null;
            $unidad = $indice[mb_strtoupper(trim((string) $fila['unidad_codigo']), 'UTF-8')] ?? null;
            if (!$prenda) {
                $constantesSinPrenda++;
                continue;
            }
            if (!$unidad) {
                $constantesSinUnidad++;
                continue;
            }
            $existe = RopaConstante::query()
                ->where('id_prenda', $prenda->id)
                ->where('id_unidad_funcional', $unidad->id)
                ->where('estado', 1)
                ->exists();
            if ($existe) {
                continue;
            }
            RopaConstante::create([
                'id_prenda' => $prenda->id,
                'id_unidad_funcional' => $unidad->id,
                'constante' => $fila['constante'],
                'estado' => 1,
            ]);
            $constantesNuevas++;
        }

        $this->command?->info("Prendas nuevas: {$prendasNuevas}");
        $this->command?->info("Cupos nuevos: {$cuposNuevos}");
        $this->command?->info("Constantes nuevas: {$constantesNuevas}");
        $this->command?->info('Unidades del catálogo sin equivalente: ' . count($unidadesFaltantes));
        foreach (array_slice($unidadesFaltantes, 0, 40) as $faltante) {
            $this->command?->warn('  ' . $faltante);
        }
        if ($constantesSinPrenda > 0 || $constantesSinUnidad > 0) {
            $this->command?->warn("Constantes omitidas. Sin prenda: {$constantesSinPrenda}. Sin unidad: {$constantesSinUnidad}.");
        }
    }

    /**
     * @return array<string, ConfigUnidadFuncional>
     */
    private function indiceUnidades(): array
    {
        $grupos = [];
        $unidades = ConfigUnidadFuncional::query()->orderBy('id')->get(['id', 'codigo', 'nombre', 'estado']);
        foreach ($unidades as $unidad) {
            $codigo = mb_strtoupper(trim((string) $unidad->codigo), 'UTF-8');
            if ($codigo === '') {
                continue;
            }
            $grupos[$codigo][] = $unidad;
        }

        $indice = [];
        foreach ($grupos as $codigo => $lista) {
            $activas = array_values(array_filter($lista, fn (ConfigUnidadFuncional $unidad) => (bool) $unidad->estado));
            $indice[$codigo] = ($activas[0] ?? $lista[0]);
        }

        return $indice;
    }
}
