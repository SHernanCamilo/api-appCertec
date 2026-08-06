<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Workflow\WfAprobador;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfRegla;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de aprobación del módulo Fichas Técnicas en el motor de flujos.
 *
 * Dos pasos, con aprobadores resueltos por rol Spatie + alcance organizacional:
 *
 *   1. Autorización Dirección Médica  → rol `autorizador-fichas`, alcance sucursal
 *   2. Aprobación VP Financiera       → rol `aprobador-fichas`,   alcance empresa
 *
 * El paso "Generar" es implícito: el flujo arranca cuando el generador envía la
 * ficha a autorización, no cuando la crea.
 *
 * Requiere: FichasTecnicasModuloSeeder ejecutado antes (crea los roles).
 */
class FichasTecnicasWorkflowSeeder extends Seeder
{
    public const MODULO = 'fichas_tecnicas';

    public const FLUJO_ESTANDAR = 'FICHAS_TECNICAS_ESTANDAR';

    public function run(): void
    {
        DB::transaction(function (): void {
            $modulo = WfModulo::updateOrCreate(
                ['codigo' => self::MODULO],
                [
                    'nombre'      => 'Fichas Técnicas Médicas',
                    'descripcion' => 'Aprobación de fichas técnicas de contratación de servicios médicos '
                        .'con agremiaciones y profesionales de la salud.',
                    'estado'      => true,
                ]
            );

            $this->flujoEstandar((int) $modulo->id);

            $this->command?->info('✓ Flujo de Fichas Técnicas configurado en el motor de flujos');
        });
    }

    /**
     * Flujo estándar: Dirección Médica → Vicepresidencia Financiera.
     *
     * Aplica a todas las empresas (id_empresa = null) y sin restricciones de
     * contexto, por lo que la regla queda con condiciones vacías y prioridad
     * alta (se evalúa al final, como flujo por defecto).
     */
    private function flujoEstandar(int $moduloId): void
    {
        $flujo = WfDefinicion::updateOrCreate(
            ['codigo' => self::FLUJO_ESTANDAR],
            [
                'nombre'      => 'Fichas Técnicas — Doble validación',
                'descripcion' => 'Enviar → Autorizar (Dirección Médica) → Aprobar (VP Financiera)',
                'id_modulo'   => $moduloId,
                'id_empresa'  => null,
                'estado'      => true,
            ]
        );

        // Re-seed idempotente: los aprobadores caen por FK en cascada.
        WfPaso::where('id_definicion', $flujo->id)->delete();

        $pasos = [
            [
                'orden'         => 1,
                'nombre_paso'   => 'Autorización Dirección Médica',
                'rol_aprobador' => 'autorizador-fichas',
                'rol_spatie'    => 'autorizador-fichas',
                'alcance'       => 'sucursal',
                'rechazo'       => true,
                'contexto'      => 'El Director Médico revisa la información médica y operativa de la ficha. '
                    .'Puede autorizar o devolver para corrección con observación obligatoria.',
            ],
            [
                'orden'         => 2,
                'nombre_paso'   => 'Aprobación Vicepresidencia Financiera',
                'rol_aprobador' => 'aprobador-fichas',
                'rol_spatie'    => 'aprobador-fichas',
                'alcance'       => 'empresa',
                'rechazo'       => true,
                'contexto'      => 'El VP Financiero revisa la información financiera y contractual. '
                    .'Al aprobar se asigna el consecutivo oficial y se registran las firmas.',
            ],
        ];

        foreach ($pasos as $p) {
            $paso = WfPaso::create([
                'id_definicion'        => $flujo->id,
                'orden'                => $p['orden'],
                'nombre_paso'          => $p['nombre_paso'],
                'rol_aprobador'        => $p['rol_aprobador'],
                'es_opcional'          => false,
                'permite_rechazo'      => $p['rechazo'],
                'requiere_monto'       => false,
                'descripcion_contexto' => $p['contexto'],
                'estado'               => true,
            ]);

            WfAprobador::create([
                'id_paso'        => $paso->id,
                'tipo_aprobador' => WfAprobador::TIPO_ROL_SPATIE,
                'rol_spatie'     => $p['rol_spatie'],
                'alcance'        => $p['alcance'],
                'es_suplente'    => false,
                'estado'         => true,
            ]);
        }

        // Flujo por defecto: sin condiciones, prioridad alta.
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo->id, 'prioridad' => 100],
            ['condiciones' => [], 'estado' => true]
        );
    }
}
