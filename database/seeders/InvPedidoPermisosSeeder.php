<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\Perfil;
use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

/**
 * Permisos del flujo de Pedidos de Farmacia.
 *
 * Regla de negocio:
 *   - El AUXILIAR DE ALMACÉN crea el pedido (no requiere permiso especial más allá
 *     del acceso al módulo).
 *   - El JEFE DE ALMACÉN lo CONFIRMA/APRUEBA (requiere el permiso 'confirmar-pedido').
 *
 * Crea, de forma idempotente:
 *   1. Permiso 'confirmar-pedido' (tipo botón) bajo el módulo INV-PEDIDOS.
 *   2. Un perfil 'INV-PEDIDO-CONFIRMAR' que agrupa ese permiso.
 *   3. Asigna ese perfil al rol 'jefe-almacen' (y a los roles admin).
 *
 * Ejecutar:  php artisan db:seed --class=InvPedidoPermisosSeeder
 * Idempotente: puede correrse varias veces sin duplicar.
 */
class InvPedidoPermisosSeeder extends Seeder
{
    /** Código del módulo de UI de Pedidos (Solicitud Pedidos). */
    private const MODULO_PEDIDOS = 'INV-PEDIDOS';

    /** Código del permiso de confirmación. */
    private const PERMISO_CONFIRMAR = 'confirmar-pedido';

    /** Rol que confirma pedidos. */
    private const ROL_JEFE = 'jefe-almacen';

    public function run(): void
    {
        $modulo = Modulo::where('codigo', self::MODULO_PEDIDOS)->first();
        if (!$modulo) {
            $this->command?->error("No existe el módulo '" . self::MODULO_PEDIDOS . "' en seg_modulos. Aborto.");
            return;
        }

        // 1. Permiso de confirmación de pedido.
        $permiso = Permiso::updateOrCreate(
            ['codigo' => self::PERMISO_CONFIRMAR],
            [
                'id_modulo'   => $modulo->id,
                'nombre'      => 'Confirmar Pedido',
                'descripcion' => 'Permite confirmar/aprobar los pedidos creados por el auxiliar de almacén',
                'tipo'        => 'boton',
                'orden'       => 1,
                'estado'      => true,
            ]
        );

        // 2. Perfil que agrupa el permiso de confirmación.
        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-PEDIDO-CONFIRMAR', 'id_modulo' => $modulo->id],
            [
                'nombre'        => 'Confirmación de Pedidos',
                'descripcion'   => 'Autoriza la confirmación/aprobación de pedidos de farmacia',
                'puede_leer'    => 1,
                'puede_crear'   => 0,
                'puede_editar'  => 1,
                'puede_eliminar'=> 0,
                'estado'        => 1,
            ]
        );

        $perfil->permisos()->syncWithoutDetaching([$permiso->id]);

        // 3. Asignar el perfil al rol Jefe de Almacén (y a los roles admin).
        $roles = Rol::query()
            ->where('estado', 1)
            ->where(function ($query): void {
                $query->where('codigo', self::ROL_JEFE)
                    ->orWhere('es_admin', 1)
                    ->orWhere('codigo', 'super-admin');
            })
            ->get();

        if ($roles->isEmpty()) {
            $this->command?->warn("No se encontró el rol '" . self::ROL_JEFE . "' ni roles admin. El permiso quedó creado pero sin asignar.");
        }

        foreach ($roles as $rol) {
            $rol->perfiles()->syncWithoutDetaching([$perfil->id]);
        }

        // Invalidar cachés de permisos/sidebar si los servicios existen.
        if (class_exists(\App\Services\SidebarService::class)) {
            app(\App\Services\SidebarService::class)->invalidateAllSidebarCache();
        }
        if (class_exists(\App\Services\PermissionCacheService::class)) {
            try { app(\App\Services\PermissionCacheService::class)->clearAllPermissions(); } catch (\Throwable $e) {}
        }

        $this->command?->info('Permiso "' . self::PERMISO_CONFIRMAR . '" creado bajo ' . self::MODULO_PEDIDOS
            . ' y asignado al rol "' . self::ROL_JEFE . '" (+ admins). Perfil: INV-PEDIDO-CONFIRMAR.');
    }
}
