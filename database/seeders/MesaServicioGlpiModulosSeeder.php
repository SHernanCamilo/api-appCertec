<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\SidebarService;
use Illuminate\Database\Seeder;

class MesaServicioGlpiModulosSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Configurando módulo Mesa de Servicio / Parametrizador GLPI...');

        $mesa = Modulo::updateOrCreate(
            ['codigo' => 'MESA'],
            [
                'id_modulo_padre' => null,
                'nombre' => 'Mesa de Servicio',
                'descripcion' => 'Parametrización y validación de mesa de ayuda GLPI',
                'icono' => 'bi bi-headset',
                'ruta' => '/mesaServicio',
                'orden' => 6,
                'nivel' => 0,
                'estado' => 1,
            ]
        );

        $parametrizador = Modulo::updateOrCreate(
            ['codigo' => 'MESA-GLPI'],
            [
                'id_modulo_padre' => $mesa->id,
                'nombre' => 'Parametrizador GLPI',
                'descripcion' => 'Plantillas ANS y validación de reglas de tickets GLPI',
                'icono' => 'bi bi-sliders',
                'ruta' => null,
                'orden' => 0,
                'nivel' => 1,
                'estado' => 1,
            ]
        );

        $plantillas = Modulo::updateOrCreate(
            ['codigo' => 'MESA-GLPI-PLANTILLA'],
            [
                'id_modulo_padre' => $parametrizador->id,
                'nombre' => 'Plantillas',
                'descripcion' => 'Crear y editar plantillas de categorías, prioridades y tiempos ANS',
                'icono' => 'bi bi-file-earmark-text',
                'ruta' => '/mesaServicio/parametrizadorGLPI/plantillas',
                'orden' => 0,
                'nivel' => 2,
                'estado' => 1,
            ]
        );

        $validador = Modulo::updateOrCreate(
            ['codigo' => 'MESA-GLPI-VALIDADOR'],
            [
                'id_modulo_padre' => $parametrizador->id,
                'nombre' => 'Validador',
                'descripcion' => 'Comparar plantillas ANS contra entidades, categorías, SLA y reglas de GLPI',
                'icono' => 'bi bi-check2-square',
                'ruta' => '/mesaServicio/parametrizadorGLPI/validador',
                'orden' => 1,
                'nivel' => 2,
                'estado' => 1,
            ]
        );

        $this->command?->info("Módulo MESA listo (ID: {$mesa->id})");
        $this->command?->info("Módulo MESA-GLPI-PLANTILLA listo (ID: {$plantillas->id})");
        $this->command?->info("Módulo MESA-GLPI-VALIDADOR listo (ID: {$validador->id})");

        $empresaIds = ModuloEmpresa::where('activo', 1)
            ->pluck('id_empresa')
            ->unique()
            ->filter()
            ->values();

        if ($empresaIds->isEmpty()) {
            $empresaIds = Empresa::query()->pluck('id');
        }

        foreach ($empresaIds as $idEmpresa) {
            $empresa = Empresa::find($idEmpresa);
            if (! $empresa) {
                continue;
            }

            foreach ([$mesa, $parametrizador, $plantillas, $validador] as $modulo) {
                $hereda = ! in_array($modulo->codigo, ['MESA-GLPI-PLANTILLA', 'MESA-GLPI-VALIDADOR'], true);

                ModuloEmpresa::updateOrCreate(
                    ['id_modulo' => $modulo->id, 'id_empresa' => $empresa->id],
                    ['activo' => 1, 'hereda_hijos' => $hereda ? 1 : 0]
                );
            }

            $this->command?->info("  Asignado a empresa: {$empresa->nombre}");
        }

        $permisoIds = $this->crearPermisos($mesa, $parametrizador, $plantillas, $validador);

        $perfilPlantillas = $this->asegurarPerfil(
            $plantillas,
            'MESA-GLPI-PLT',
            'Gestión Plantillas GLPI',
            'Permite crear, consultar, editar y eliminar plantillas ANS de GLPI',
            array_values(array_filter($permisoIds, fn ($item) => ! str_starts_with($item['codigo'], 'mesa-glpi-validador'))),
        );

        $perfilValidador = $this->asegurarPerfil(
            $validador,
            'MESA-GLPI-VAL',
            'Validador GLPI',
            'Permite comparar plantillas ANS contra entidades y reglas de GLPI',
            array_values(array_filter($permisoIds, fn ($item) => str_starts_with($item['codigo'], 'mesa-glpi-validador') || in_array($item['codigo'], ['mesa-visible', 'mesa-glpi-visible'], true))),
        );

        $this->asignarPerfilesARoles([$perfilPlantillas->id, $perfilValidador->id]);

        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command?->info('Módulo Mesa de Servicio / Parametrizador GLPI configurado.');
    }

    /**
     * @return list<array{id: int, codigo: string}>
     */
    private function crearPermisos(Modulo $mesa, Modulo $parametrizador, Modulo $plantillas, Modulo $validador): array
    {
        $definiciones = [
            [
                'id_modulo' => $mesa->id,
                'nombre' => 'Visible Mesa de Servicio',
                'codigo' => 'mesa-visible',
                'descripcion' => 'Permite ver Mesa de Servicio en el menú',
                'tipo' => 'menu',
                'orden' => 0,
            ],
            [
                'id_modulo' => $parametrizador->id,
                'nombre' => 'Visible Parametrizador GLPI',
                'codigo' => 'mesa-glpi-visible',
                'descripcion' => 'Permite ver Parametrizador GLPI en el menú',
                'tipo' => 'menu',
                'orden' => 0,
            ],
            [
                'id_modulo' => $plantillas->id,
                'nombre' => 'Visible Plantillas GLPI',
                'codigo' => 'mesa-glpi-plantilla-visible',
                'descripcion' => 'Permite ver Plantillas GLPI en el menú',
                'tipo' => 'menu',
                'orden' => 0,
            ],
            [
                'id_modulo' => $plantillas->id,
                'nombre' => 'Ver Plantillas GLPI',
                'codigo' => 'mesa-glpi-plantilla-ver',
                'descripcion' => 'Permite consultar plantillas ANS de GLPI',
                'tipo' => 'accion',
                'orden' => 1,
            ],
            [
                'id_modulo' => $plantillas->id,
                'nombre' => 'Crear Plantilla GLPI',
                'codigo' => 'mesa-glpi-plantilla-crear',
                'descripcion' => 'Permite crear plantillas ANS de GLPI',
                'tipo' => 'boton',
                'orden' => 2,
            ],
            [
                'id_modulo' => $plantillas->id,
                'nombre' => 'Editar Plantilla GLPI',
                'codigo' => 'mesa-glpi-plantilla-editar',
                'descripcion' => 'Permite editar plantillas ANS de GLPI',
                'tipo' => 'boton',
                'orden' => 3,
            ],
            [
                'id_modulo' => $plantillas->id,
                'nombre' => 'Eliminar Plantilla GLPI',
                'codigo' => 'mesa-glpi-plantilla-eliminar',
                'descripcion' => 'Permite eliminar plantillas ANS de GLPI',
                'tipo' => 'boton',
                'orden' => 4,
            ],
            [
                'id_modulo' => $validador->id,
                'nombre' => 'Visible Validador GLPI',
                'codigo' => 'mesa-glpi-validador-visible',
                'descripcion' => 'Permite ver el Validador GLPI en el menú',
                'tipo' => 'menu',
                'orden' => 0,
            ],
            [
                'id_modulo' => $validador->id,
                'nombre' => 'Ver Validador GLPI',
                'codigo' => 'mesa-glpi-validador-ver',
                'descripcion' => 'Permite consultar el árbol de entidades y resultados de comparación',
                'tipo' => 'accion',
                'orden' => 1,
            ],
            [
                'id_modulo' => $validador->id,
                'nombre' => 'Comparar plantilla GLPI',
                'codigo' => 'mesa-glpi-validador-comparar',
                'descripcion' => 'Permite comparar una plantilla contra una entidad de GLPI',
                'tipo' => 'boton',
                'orden' => 2,
            ],
        ];

        $items = [];

        foreach ($definiciones as $def) {
            $permiso = Permiso::updateOrCreate(
                ['codigo' => $def['codigo']],
                [
                    'id_modulo' => $def['id_modulo'],
                    'nombre' => $def['nombre'],
                    'descripcion' => $def['descripcion'],
                    'tipo' => $def['tipo'],
                    'orden' => $def['orden'],
                    'estado' => true,
                ]
            );
            $items[] = ['id' => $permiso->id, 'codigo' => $permiso->codigo];
            $this->command?->info("  Permiso: {$permiso->codigo}");
        }

        return $items;
    }

    /**
     * @param  list<array{id: int, codigo: string}>  $permisos
     */
    private function asegurarPerfil(
        Modulo $modulo,
        string $codigo,
        string $nombre,
        string $descripcion,
        array $permisos
    ): Perfil {
        $perfil = Perfil::query()
            ->where('id_modulo', $modulo->id)
            ->where('codigo', $codigo)
            ->first();

        $data = [
            'codigo' => $codigo,
            'id_modulo' => $modulo->id,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'puede_leer' => 1,
            'puede_crear' => 0,
            'puede_editar' => 0,
            'puede_eliminar' => 0,
            'estado' => 1,
        ];

        if ($codigo === 'MESA-GLPI-PLT') {
            $data['puede_crear'] = 1;
            $data['puede_editar'] = 1;
            $data['puede_eliminar'] = 1;
        }

        if ($perfil) {
            $perfil->update($data);
        } else {
            $perfil = Perfil::create($data);
        }

        $perfil->permisos()->sync(array_column($permisos, 'id'));
        $this->command?->info("Perfil '{$perfil->nombre}' listo (ID: {$perfil->id}, codigo: {$perfil->codigo})");

        return $perfil;
    }

    /**
     * @param  list<int>  $perfilIds
     */
    private function asignarPerfilesARoles(array $perfilIds): void
    {
        $roles = Rol::query()
            ->where('estado', 1)
            ->where(function ($query): void {
                $query->where('es_admin', 1)
                    ->orWhere('codigo', 'super-admin')
                    ->orWhere('nombre', 'like', '%Super Administrador%')
                    ->orWhere('nombre', 'like', '%Mesa de ayuda%');
            })
            ->get();

        $rolesConPlantilla = Rol::query()
            ->whereHas('perfiles', fn ($q) => $q->where('codigo', 'MESA-GLPI-PLT'))
            ->get();

        $roles = $roles->merge($rolesConPlantilla)->unique('id');

        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching($perfilIds);
            $this->command?->info("  Perfiles asignados al rol: {$rol->nombre}");
        }
    }
}
