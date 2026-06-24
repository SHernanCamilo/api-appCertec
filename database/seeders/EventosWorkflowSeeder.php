<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfRegla;
use App\Models\Workflow\WfAprobador;
use Illuminate\Support\Facades\DB;

/**
 * Flujos de aprobación para el módulo Eventos.
 *
 * Aprobadores resueltos por PERMISO + alcance organizacional:
 *   - apro-evento  + uf        → responsables UF del evento con permiso
 *   - auto-evento  + sucursal  → usuarios de la sucursal de la UF + permiso
 *   - digi-evento  + empresa   → usuarios de la empresa + permiso
 *
 * Selección de flujo por unidad funcional + empresa (wf_reglas.condiciones).
 *
 * Requiere: PermisosEventosSeeder ejecutado antes.
 */
class EventosWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $modulo = WfModulo::updateOrCreate(
                ['codigo' => 'eventos'],
                [
                    'nombre'      => 'Eventos',
                    'descripcion' => 'Eventos de talento humano (horas extras, cuadro de turno)',
                    'estado'      => true,
                ]
            );

            // IMPORTANTE: WorkflowResolver evalúa los flujos por orden de id y devuelve
            // el primero cuya regla coincide. Por eso los flujos ESPECÍFICOS (por UF) se
            // siembran primero y el flujo POR DEFECTO (condiciones vacías) al final.
            $this->flujoSinAprobacion($modulo->id);
            $this->flujoCrossEmpresa($modulo->id);
            $this->flujoCompleto($modulo->id); // default, debe quedar de último

            $this->command?->info('Flujos de eventos creados.');
        });
    }

    /**
     * Flujo 1: Solicitar -> Aprobar -> Autorizar -> Digitalizar
     * Aplica por defecto (sin restricción de UF).
     */
    private function flujoCompleto(int $moduloId): void
    {
        $flujo = WfDefinicion::updateOrCreate(
            ['codigo' => 'EVENTOS_COMPLETO'],
            [
                'nombre'      => 'Eventos - Flujo completo (Aprobar + Autorizar + Digitalizar)',
                'descripcion' => 'Solicitar -> Aprobar -> Autorizar -> Digitalizar',
                'id_modulo'   => $moduloId,
                'id_empresa'  => null,
                'estado'      => true,
            ]
        );

        // "Solicitar" es implícito (la creación del evento). El flujo arranca en Aprobar.
        $pasos = [
            ['orden' => 1, 'nombre_paso' => 'Aprobar',     'rol_aprobador' => 'aprobador',     'permiso' => 'apro-evento',  'alcance' => 'uf',        'rechazo' => true],
            ['orden' => 2, 'nombre_paso' => 'Autorizar',   'rol_aprobador' => 'autorizador',   'permiso' => 'auto-evento',  'alcance' => 'sucursal',  'rechazo' => true],
            ['orden' => 3, 'nombre_paso' => 'Digitalizar', 'rol_aprobador' => 'digitalizador', 'permiso' => 'digi-evento',  'alcance' => 'empresa',   'rechazo' => false],
        ];

        $this->crearPasosConPermiso($flujo->id, $pasos);

        // Prioridad alta (número alto = se evalúa al final, como flujo por defecto)
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo->id, 'prioridad' => 100],
            ['condiciones' => [], 'estado' => true]
        );
    }

    /**
     * Flujo 2: Solicitar -> Autorizar -> Digitalizar (sin paso Aprobar)
     * Ejemplo amarrado a una unidad funcional específica.
     */
    private function flujoSinAprobacion(int $moduloId): void
    {
        $flujo = WfDefinicion::updateOrCreate(
            ['codigo' => 'EVENTOS_SIN_APROBACION'],
            [
                'nombre'      => 'Eventos - Sin aprobación intermedia',
                'descripcion' => 'Solicitar -> Autorizar -> Digitalizar',
                'id_modulo'   => $moduloId,
                'id_empresa'  => null,
                'estado'      => true,
            ]
        );

        $pasos = [
            ['orden' => 1, 'nombre_paso' => 'Autorizar',   'rol_aprobador' => 'autorizador',   'permiso' => 'auto-evento',  'alcance' => 'sucursal', 'rechazo' => true],
            ['orden' => 2, 'nombre_paso' => 'Digitalizar', 'rol_aprobador' => 'digitalizador', 'permiso' => 'digi-evento',  'alcance' => 'empresa',  'rechazo' => false],
        ];

        $this->crearPasosConPermiso($flujo->id, $pasos);

        // EDITAR: reemplazar id_unidad_funcional por el real de tu BD
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo->id, 'prioridad' => 10],
            ['condiciones' => ['id_unidad_funcional' => 0], 'estado' => true]
        );
    }

    /**
     * Flujo 3: Solicitar -> Aprobar (otra empresa) -> Autorizar -> Digitalizar
     * El paso "Aprobar" usa permiso sin filtro de empresa, de modo que un usuario
     * de otra empresa con 'apro-evento' queda habilitado.
     */
    private function flujoCrossEmpresa(int $moduloId): void
    {
        $flujo = WfDefinicion::updateOrCreate(
            ['codigo' => 'EVENTOS_CROSS_EMPRESA'],
            [
                'nombre'      => 'Eventos - Aprobador de otra empresa',
                'descripcion' => 'Solicitar -> Aprobar (cross-empresa) -> Autorizar -> Digitalizar',
                'id_modulo'   => $moduloId,
                'id_empresa'  => null,
                'estado'      => true,
            ]
        );

        $pasos = [
            ['orden' => 1, 'nombre_paso' => 'Aprobar (otra empresa)', 'rol_aprobador' => 'aprobador',     'permiso' => 'apro-evento', 'alcance' => 'empresa',  'rechazo' => true],
            ['orden' => 2, 'nombre_paso' => 'Autorizar',              'rol_aprobador' => 'autorizador',   'permiso' => 'auto-evento', 'alcance' => 'sucursal', 'rechazo' => true],
            ['orden' => 3, 'nombre_paso' => 'Digitalizar',            'rol_aprobador' => 'digitalizador', 'permiso' => 'digi-evento', 'alcance' => 'empresa',  'rechazo' => false],
        ];

        $this->crearPasosConPermiso($flujo->id, $pasos);

        // EDITAR: reemplazar id_unidad_funcional por el real de tu BD
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo->id, 'prioridad' => 20],
            ['condiciones' => ['id_unidad_funcional' => 0], 'estado' => true]
        );
    }

    /**
     * Crea pasos y su aprobador por permiso.
     * Limpia los pasos previos del flujo para que el re-seed sea idempotente
     * (los aprobadores se eliminan en cascada por FK).
     */
    private function crearPasosConPermiso(int $idDefinicion, array $pasos): void
    {
        WfPaso::where('id_definicion', $idDefinicion)->delete();

        foreach ($pasos as $p) {
            $paso = WfPaso::create([
                'id_definicion'   => $idDefinicion,
                'orden'           => $p['orden'],
                'nombre_paso'     => $p['nombre_paso'],
                'rol_aprobador'   => $p['rol_aprobador'],
                'es_opcional'     => false,
                'permite_rechazo' => $p['rechazo'],
                'requiere_monto'  => false,
                'estado'          => true,
            ]);

            WfAprobador::create([
                'id_paso'        => $paso->id,
                'permiso_codigo' => $p['permiso'],
                'alcance'        => $p['alcance'] ?? 'empresa',
                'es_suplente'    => false,
                'estado'         => true,
            ]);
        }
    }
}
