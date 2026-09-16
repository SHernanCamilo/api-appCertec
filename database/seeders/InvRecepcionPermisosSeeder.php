<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\Perfil;
use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

/**
 * Permisos del flujo de Recepción Técnica de Farmacia.
 *
 * Regla de negocio:
 *   - El AUXILIAR / TÉCNICO recepciona los productos (registra lote, vencimiento,
 *     cantidades, cumplimientos...). La recepción queda en estado 'RECEPCIONADO'
 *     (parcial), y se puede seguir recepcionando lo que falta.
 *   - El JEFE DE ALMACÉN FINALIZA/CONFIRMA la recepción (estado 'CONFIRMADO'),
 *     lo que la vuelve de solo lectura y marca la OC como recibida. Requiere el
 *     permiso 'confirmar-recepcion'.
 *
 * Crea, de forma idempotente:
 *   1. Permiso 'confirmar-recepcion' (tipo botón) bajo el módulo INV-RECEPCIONES.
 *   2. Un perfil 'INV-RECEPCION-CONFIRMAR' que agrupa ese permiso.
 *   3. Asigna ese perfil al rol 'jefe-almacen' (y a los roles admin).
 *
 * Ejecutar:  php artisan db:seed --class=InvRecepcionPermisosSeeder
 * Idempotente: puede correrse varias veces sin duplicar.
 */
class InvRecepcionPermisosSeeder extends Seeder
{
    /** Código del módulo de UI de Recepción Técnica. */
    private const MODULO_RECEPCIONES = 'INV-RECEPCIONES';

    /** Código del permiso de confirmación de recepción. */
    private const PERMISO_CONFIRMAR = 'confirmar-recepcion';

    /** Rol que confirma la recepción técnica. */
    private const ROL_JEFE = 'jefe-almacen';

    public function run(): void
    {
        $modulo = Modulo::where('codigo', self::MODULO_RECEPCIONES)->first();
        if (!$modulo) {
            $this->command?->error("No existe el módulo '" . self::MODULO_RECEPCIONES . "' en seg_modulos. Aborto.");
            return;
        }

        // 1. Permiso de confirmación de recepción técnica.
        $permiso = Permiso::updateOrCreate(
            ['codigo' => self::PERMISO_CONFIRMAR],
            [
                'id_modulo'   => $modulo->id,
                'nombre'      => 'Confirmar Recepción Técnica',
                'descripcion' => 'Permite finalizar/confirmar la recepción técnica (marca la OC como recibida y bloquea la edición)',
                'tipo'        => 'boton',
                'orden'       => 1,
                'estado'      => true,
            ]
        );

        // 2. Perfil que agrupa el permiso de confirmación.
        $perfil = Perfil::updateOrCreate(
            ['codigo' => 'INV-RECEPCION-CONFIRMAR', 'id_modulo' => $modulo->id],
            [
                'nombre'        => 'Confirmación de Recepción Técnica',
                'descripcion'   => 'Autoriza la finalización/confirmación de la recepción técnica de farmacia',
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

        $this->command?->info('Permiso "' . self::PERMISO_CONFIRMAR . '" creado bajo ' . self::MODULO_RECEPCIONES
            . ' y asignado al rol "' . self::ROL_JEFE . '" (+ admins). Perfil: INV-RECEPCION-CONFIRMAR.');
    }
}
