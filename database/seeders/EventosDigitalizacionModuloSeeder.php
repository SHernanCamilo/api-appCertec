<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Perfil;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\SidebarService;
use Illuminate\Database\Seeder;

/**
 * Crea el módulo Digitalización como hijo de Eventos (TALHUM-EVEN)
 * para que aparezca en el menú de Talento Humano por permiso.
 */
class EventosDigitalizacionModuloSeeder extends Seeder
{
    public function run(): void
    {
        $eventos = Modulo::query()
            ->where('codigo', 'TALHUM-EVEN')
            ->orWhere('ruta', '/talentoHumano/eventos')
            ->first();

        if (!$eventos) {
            $this->command?->error('No se encontró el módulo Eventos (TALHUM-EVEN). Créalo antes de ejecutar este seeder.');
            return;
        }

        $modulo = Modulo::updateOrCreate(
            ['codigo' => 'TALHUM-EVENT-DIGIT'],
            [
                'id_modulo_padre' => $eventos->id,
                'nombre'          => 'Digitalización',
                'descripcion'     => 'Cierre de eventos autorizados para el cargue de nómina',
                'icono'           => 'bi bi-upload',
                'ruta'            => '/talentoHumano/eventos/digitalizacion',
                'orden'           => 2,
                'nivel'           => ((int) $eventos->nivel) + 1,
                'estado'          => 1,
            ]
        );

        $this->command?->info("Módulo Digitalización listo (ID: {$modulo->id})");

        $empresaIds = ModuloEmpresa::query()
            ->where('id_modulo', $eventos->id)
            ->where('activo', 1)
            ->pluck('id_empresa')
            ->unique()
            ->filter()
            ->values();

        foreach ($empresaIds as $idEmpresa) {
            ModuloEmpresa::updateOrCreate(
                ['id_modulo' => $modulo->id, 'id_empresa' => $idEmpresa],
                ['activo' => 1, 'hereda_hijos' => 0]
            );
        }

        $permisos = $this->crearPermisos($modulo);

        $perfil = $this->asegurarPerfil($modulo, $permisos);

        $this->asignarPerfilARoles($perfil, $eventos->id);

        app(SidebarService::class)->invalidateAllSidebarCache();

        $this->command?->info('Módulo Digitalización configurado. Cierre sesión y vuelva a entrar para verlo en el menú.');
    }

    /**
     * @return list<array{id: int, codigo: string}>
     */
    private function crearPermisos(Modulo $modulo): array
    {
        $definiciones = [
            [
                'codigo'      => 'talhum-event-digit-visible',
                'nombre'      => 'Visible Digitalización',
                'descripcion' => 'Permite ver Digitalización en el menú de Eventos',
                'tipo'        => 'menu',
                'orden'       => 0,
            ],
            [
                'codigo'      => 'talhum-event-digit-ver',
                'nombre'      => 'Ver cola de digitalización',
                'descripcion' => 'Permite consultar eventos autorizados pendientes de cargue a nómina',
                'tipo'        => 'accion',
                'orden'       => 1,
            ],
            [
                'codigo'      => 'talhum-event-digit-ejecutar',
                'nombre'      => 'Digitalizar evento',
                'descripcion' => 'Permite marcar eventos como digitalizados para el pago',
                'tipo'        => 'boton',
                'orden'       => 2,
            ],
        ];

        $items = [];
        foreach ($definiciones as $def) {
            $permiso = Permiso::updateOrCreate(
                ['codigo' => $def['codigo']],
                [
                    'id_modulo'   => $modulo->id,
                    'nombre'      => $def['nombre'],
                    'descripcion' => $def['descripcion'],
                    'tipo'        => $def['tipo'],
                    'orden'       => $def['orden'],
                    'estado'      => true,
                ]
            );
            $items[] = ['id' => $permiso->id, 'codigo' => $permiso->codigo];
        }

        $digiEvento = Permiso::query()->where('codigo', 'digi-evento')->first();
        if ($digiEvento) {
            $items[] = ['id' => $digiEvento->id, 'codigo' => $digiEvento->codigo];
        }

        return $items;
    }

    /**
     * @param  list<array{id: int, codigo: string}>  $permisos
     */
    private function asegurarPerfil(Modulo $modulo, array $permisos): Perfil
    {
        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'TALHUM-EVENT-DIGIT'],
            [
                'id_modulo'      => $modulo->id,
                'nombre'         => 'Digitalización de Eventos',
                'descripcion'    => 'Cierre de eventos autorizados para el cargue de nómina',
                'puede_leer'     => 1,
                'puede_crear'    => 0,
                'puede_editar'   => 1,
                'puede_eliminar' => 0,
                'estado'         => 1,
            ]
        );

        $perfil->permisos()->sync(array_column($permisos, 'id'));

        return $perfil;
    }

    private function asignarPerfilARoles(Perfil $perfil, int $idModuloEventos): void
    {
        $roles = Rol::query()
            ->where('estado', 1)
            ->where(function ($query) {
                $query->where('es_admin', 1)
                    ->orWhere('codigo', 'like', '%super%')
                    ->orWhere('nombre', 'like', '%Super Administrador%');
            })
            ->get();

        $rolesConEventos = Rol::query()
            ->whereHas('perfiles', fn ($q) => $q->where('id_modulo', $idModuloEventos))
            ->get();

        $roles = $roles->merge($rolesConEventos)->unique('id');

        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
            $this->command?->info("  Perfil asignado a rol: {$rol->nombre}");
        }
    }
}
