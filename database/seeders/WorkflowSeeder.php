<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfRegla;
use App\Models\Finance\AntiCiudad;
use App\Models\AntiRegla;
use App\Models\Cargo;
use Illuminate\Support\Facades\DB;

/**
 * Seeder para el motor de flujos y datos de anticipos.
 *
 * Crea:
 *   - Módulos (anticipos, horas_extras, etc.)
 *   - Flujos de aprobación parametrizados
 *   - Ciudades clasificadas por tipo
 *   - Reglas de topes por nivel jerárquico
 *   - Niveles jerárquicos en cargos
 */
class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->crearModulos();
            $this->crearCiudades();
            $this->crearFlujos();
            $this->crearReglasAnticipo();
            
            $this->command->info('✅ Datos de workflow y anticipos creados exitosamente');
        });
    }

    private function crearModulos(): void
    {
        $modulos = [
            ['codigo' => 'anticipos', 'nombre' => 'Anticipos de Viaje', 'descripcion' => 'Solicitudes de anticipo para viajes nacionales e internacionales'],
            ['codigo' => 'horas_extras', 'nombre' => 'Horas Extras', 'descripcion' => 'Solicitudes de horas extras'],
            ['codigo' => 'eventos', 'nombre' => 'Eventos', 'descripcion' => 'Solicitudes de eventos corporativos'],
            ['codigo' => 'permisos', 'nombre' => 'Permisos', 'descripcion' => 'Solicitudes de permisos laborales'],
        ];

        foreach ($modulos as $modulo) {
            WfModulo::updateOrCreate(
                ['codigo' => $modulo['codigo']],
                $modulo
            );
        }

        $this->command->info('  → Módulos creados');
    }

    private function crearCiudades(): void
    {
        $ciudades = [
            // Tipo A: Capitales principales
            ['nombre' => 'Bogotá', 'departamento' => 'Cundinamarca', 'tipo_ciudad' => 'A'],
            ['nombre' => 'Medellín', 'departamento' => 'Antioquia', 'tipo_ciudad' => 'A'],
            ['nombre' => 'Cali', 'departamento' => 'Valle del Cauca', 'tipo_ciudad' => 'A'],
            ['nombre' => 'Barranquilla', 'departamento' => 'Atlántico', 'tipo_ciudad' => 'A'],
            ['nombre' => 'Cartagena', 'departamento' => 'Bolívar', 'tipo_ciudad' => 'A'],

            // Tipo B: Capitales intermedias
            ['nombre' => 'Neiva', 'departamento' => 'Huila', 'tipo_ciudad' => 'B'],
            ['nombre' => 'Pasto', 'departamento' => 'Nariño', 'tipo_ciudad' => 'B'],
            ['nombre' => 'Pereira', 'departamento' => 'Risaralda', 'tipo_ciudad' => 'B'],
            ['nombre' => 'Tunja', 'departamento' => 'Boyacá', 'tipo_ciudad' => 'B'],
            ['nombre' => 'Yopal', 'departamento' => 'Casanare', 'tipo_ciudad' => 'B'],
            ['nombre' => 'Montería', 'departamento' => 'Córdoba', 'tipo_ciudad' => 'B'],
            ['nombre' => 'Florencia', 'departamento' => 'Caquetá', 'tipo_ciudad' => 'B'],

            // Tipo C: Municipios
            ['nombre' => 'Pitalito', 'departamento' => 'Huila', 'tipo_ciudad' => 'C'],
            ['nombre' => 'Duitama', 'departamento' => 'Boyacá', 'tipo_ciudad' => 'C'],
            ['nombre' => 'Garzón', 'departamento' => 'Huila', 'tipo_ciudad' => 'C'],
            ['nombre' => 'Puerto Asís', 'departamento' => 'Putumayo', 'tipo_ciudad' => 'C'],
            ['nombre' => 'Tumaco', 'departamento' => 'Nariño', 'tipo_ciudad' => 'C'],
        ];

        foreach ($ciudades as $ciudad) {
            AntiCiudad::updateOrCreate(
                ['nombre' => $ciudad['nombre']],
                $ciudad
            );
        }

        $this->command->info('  → Ciudades creadas');
    }

    private function crearFlujos(): void
    {
        $moduloAnticipos = WfModulo::where('codigo', 'anticipos')->first();

        // Flujo 1: Nacional MA (Nivel 1-3) - Jefe → Financiero
        $flujo1 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_NAL_MA_N123'],
            [
                'nombre' => 'Anticipo Nacional MA - Niveles 1-3',
                'descripcion' => 'Flujo para anticipos nacionales de sucursal MA (Nacional) para niveles 1, 2 y 3',
                'id_modulo' => $moduloAnticipos->id,
                'id_empresa' => null, // Aplica a todas las empresas
                'estado' => true,
            ]
        );

        // Pasos del flujo 1
        WfPaso::updateOrCreate(
            ['id_definicion' => $flujo1->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Jefe Inmediato',
                'rol_aprobador' => 'jefe_inmediato',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'estado' => true,
            ]
        );

        WfPaso::updateOrCreate(
            ['id_definicion' => $flujo1->id, 'orden' => 2],
            [
                'nombre_paso' => 'Aprobación Dirección Financiera',
                'rol_aprobador' => 'financiero',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'estado' => true,
            ]
        );

        // Regla del flujo 1
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo1->id, 'prioridad' => 10],
            [
                'condiciones' => [
                    'nivel_min' => 1,
                    'nivel_max' => 3,
                    'prefijo' => 'MA',
                    'cobertura' => 'nacional',
                ],
                'estado' => true,
            ]
        );

        // Flujo 2: Nacional Sucursales (NVA, EAL, TJA, FLA) - Nivel 1-3
        $flujo2 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_NAL_SUCURSAL_N123'],
            [
                'nombre' => 'Anticipo Nacional Sucursales - Niveles 1-3',
                'descripcion' => 'Flujo para anticipos nacionales de sucursales regionales (NVA, EAL, TJA, FLA)',
                'id_modulo' => $moduloAnticipos->id,
                'id_empresa' => null,
                'estado' => true,
            ]
        );

        WfPaso::updateOrCreate(
            ['id_definicion' => $flujo2->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Jefe Inmediato',
                'rol_aprobador' => 'jefe_inmediato',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'estado' => true,
            ]
        );

        WfPaso::updateOrCreate(
            ['id_definicion' => $flujo2->id, 'orden' => 2],
            [
                'nombre_paso' => 'Aprobación Dirección Financiera Regional',
                'rol_aprobador' => 'financiero',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'estado' => true,
            ]
        );

        // Reglas para cada prefijo
        $prefijos = ['NVA', 'EAL', 'TJA', 'FLA'];
        foreach ($prefijos as $index => $prefijo) {
            WfRegla::updateOrCreate(
                ['id_definicion' => $flujo2->id, 'prioridad' => 20 + $index],
                [
                    'condiciones' => [
                        'nivel_min' => 1,
                        'nivel_max' => 3,
                        'prefijo' => $prefijo,
                        'cobertura' => 'nacional',
                    ],
                    'estado' => true,
                ]
            );
        }

        // Flujo 3: Nivel 4+ (Vicepresidencia) - Escalamiento
        $flujo3 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_NAL_VP'],
            [
                'nombre' => 'Anticipo Nacional - Vicepresidencia',
                'descripcion' => 'Flujo para anticipos de nivel jerárquico 4 o superior (requiere aprobación VP)',
                'id_modulo' => $moduloAnticipos->id,
                'id_empresa' => null,
                'estado' => true,
            ]
        );

        WfPaso::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Dirección Financiera',
                'rol_aprobador' => 'financiero',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'estado' => true,
            ]
        );

        WfPaso::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'orden' => 2],
            [
                'nombre_paso' => 'Aprobación Vicepresidencia',
                'rol_aprobador' => 'vicepresidente',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'estado' => true,
            ]
        );

        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'prioridad' => 5],
            [
                'condiciones' => [
                    'nivel_min' => 4,
                    'cobertura' => 'nacional',
                ],
                'estado' => true,
            ]
        );

        $this->command->info('  → Flujos de aprobación creados');
    }

    private function crearReglasAnticipo(): void
    {
        // Asumiendo que ya existen los conceptos con IDs:
        // 1 = Alimentación Nacional
        // 2 = Transporte Nacional

        $reglas = [
            // Alimentación - Nivel 1 (Estratégico)
            ['id_concepto' => 1, 'nivel_jerarquico' => 1, 'descripcion' => 'Desayuno', 'valor_tope' => 35000],
            ['id_concepto' => 1, 'nivel_jerarquico' => 1, 'descripcion' => 'Almuerzo', 'valor_tope' => 45000],
            ['id_concepto' => 1, 'nivel_jerarquico' => 1, 'descripcion' => 'Cena', 'valor_tope' => 45000],

            // Alimentación - Nivel 2 (Táctico)
            ['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Desayuno', 'valor_tope' => 30000],
            ['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Almuerzo', 'valor_tope' => 40000],
            ['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Cena', 'valor_tope' => 40000],

            // Alimentación - Nivel 3 (Operativo)
            ['id_concepto' => 1, 'nivel_jerarquico' => 3, 'descripcion' => 'Desayuno', 'valor_tope' => 30000],
            ['id_concepto' => 1, 'nivel_jerarquico' => 3, 'descripcion' => 'Almuerzo', 'valor_tope' => 40000],
            ['id_concepto' => 1, 'nivel_jerarquico' => 3, 'descripcion' => 'Cena', 'valor_tope' => 40000],

            // Transporte - Aplica a todos los niveles (0 = todos)
            ['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo A', 'valor_tope' => 70000],
            ['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo B', 'valor_tope' => 50000],
            ['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo C', 'valor_tope' => 40000],
        ];

        foreach ($reglas as $regla) {
            AntiRegla::updateOrCreate(
                [
                    'id_concepto' => $regla['id_concepto'],
                    'nivel_jerarquico' => $regla['nivel_jerarquico'],
                    'descripcion' => $regla['descripcion'],
                ],
                [
                    'valor_tope' => $regla['valor_tope'],
                    'estado' => true,
                ]
            );
        }

        $this->command->info('  → Reglas de topes creadas');
    }
}
