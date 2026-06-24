<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permiso;
use App\Models\Modulo;

/**
 * Permisos de acción para el flujo de Eventos (Talento Humano).
 *
 * Estos códigos se usan como estrategia de aprobador "por permiso"
 * en el motor de flujos (wf_aprobadores.permiso_codigo).
 *
 * Los usuarios que tengan estos permisos (vía Rol -> Perfil -> Permiso)
 * se postulan automáticamente como aprobadores del paso correspondiente.
 */
class PermisosEventosSeeder extends Seeder
{
    public function run(): void
    {
        $modulo = $this->resolverModuloEventos();

        if (!$modulo) {
            $this->command?->error('No se encontró el módulo de Eventos. Crea el módulo antes de ejecutar este seeder.');
            return;
        }

        $this->command?->info("Módulo de eventos: {$modulo->nombre} (ID: {$modulo->id})");

        $permisos = [
            [
                'codigo'      => 'carga-evento',
                'nombre'      => 'Cargar/Solicitar Evento',
                'descripcion' => 'Permite registrar (solicitar) eventos a empleados de su unidad funcional',
                'orden'       => 1,
            ],
            [
                'codigo'      => 'apro-evento',
                'nombre'      => 'Aprobar Evento',
                'descripcion' => 'Habilita al usuario como aprobador en el paso "Aprobar" del flujo de eventos',
                'orden'       => 2,
            ],
            [
                'codigo'      => 'auto-evento',
                'nombre'      => 'Autorizar Evento',
                'descripcion' => 'Habilita al usuario como autorizador en el paso "Autorizar" del flujo de eventos',
                'orden'       => 3,
            ],
            [
                'codigo'      => 'digi-evento',
                'nombre'      => 'Digitalizar Evento',
                'descripcion' => 'Habilita al usuario para el paso "Digitalizar" del flujo de eventos',
                'orden'       => 4,
            ],
        ];

        foreach ($permisos as $permiso) {
            Permiso::updateOrCreate(
                ['codigo' => $permiso['codigo']],
                [
                    'id_modulo'   => $modulo->id,
                    'nombre'      => $permiso['nombre'],
                    'descripcion' => $permiso['descripcion'],
                    'tipo'        => 'accion',
                    'icono'       => 'check-circle',
                    'orden'       => $permiso['orden'],
                    'estado'      => true,
                ]
            );
            $this->command?->info("  → {$permiso['codigo']}");
        }

        $this->command?->info('Permisos de eventos creados.');
    }

    private function resolverModuloEventos(): ?Modulo
    {
        return Modulo::query()
            ->where('estado', 1)
            ->where(function ($q) {
                $q->where('ruta', 'like', '%eventos/dashboard%')
                    ->orWhere('nombre', 'Dashboard Evento')
                    ->orWhere('codigo', 'like', '%EVENT%');
            })
            ->orderByRaw("CASE WHEN ruta LIKE '%eventos/dashboard%' THEN 0 ELSE 1 END")
            ->first();
    }
}
