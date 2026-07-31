<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de parametrizaci+¦n de recargos y jornada laboral.
 * Valores basados en legislaci+¦n colombiana (CST + Ley 2101 de 2021).
 *
 * Ejecutar: php artisan db:seed --class=ParametrizacionTurnosSeeder
 */
class ParametrizacionTurnosSeeder extends Seeder
{
    public function run(): void
    {
        // ÔöÇÔöÇÔöÇ TIPOS DE RECARGO ÔöÇÔöÇÔöÇ
        $tipos = [
            [
                'codigo'                   => 'RN',
                'nombre'                   => 'Recargo Nocturno',
                'porcentaje'               => 35.00,
                'es_hora_extra'            => false,
                'aplica_dominical_festivo'  => false,
                'hora_inicio'              => '21:00',
                'hora_fin'                 => '06:00',
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'codigo'                   => 'HED',
                'nombre'                   => 'Hora Extra Diurna',
                'porcentaje'               => 25.00,
                'es_hora_extra'            => true,
                'aplica_dominical_festivo'  => false,
                'hora_inicio'              => '06:00',
                'hora_fin'                 => '21:00',
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'codigo'                   => 'HEN',
                'nombre'                   => 'Hora Extra Nocturna',
                'porcentaje'               => 75.00,
                'es_hora_extra'            => true,
                'aplica_dominical_festivo'  => false,
                'hora_inicio'              => '21:00',
                'hora_fin'                 => '06:00',
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'codigo'                   => 'RDF',
                'nombre'                   => 'Recargo Dominical y Festivo',
                'porcentaje'               => 75.00,
                'es_hora_extra'            => false,
                'aplica_dominical_festivo'  => true,
                'hora_inicio'              => null,
                'hora_fin'                 => null,
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'codigo'                   => 'RNDF',
                'nombre'                   => 'Recargo Nocturno Dominical/Festivo',
                'porcentaje'               => 110.00,
                'es_hora_extra'            => false,
                'aplica_dominical_festivo'  => true,
                'hora_inicio'              => '21:00',
                'hora_fin'                 => '06:00',
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'codigo'                   => 'HEDF',
                'nombre'                   => 'Hora Extra Diurna Dominical/Festivo',
                'porcentaje'               => 100.00,
                'es_hora_extra'            => true,
                'aplica_dominical_festivo'  => true,
                'hora_inicio'              => '06:00',
                'hora_fin'                 => '21:00',
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
            [
                'codigo'                   => 'HENF',
                'nombre'                   => 'Hora Extra Nocturna Dominical/Festivo',
                'porcentaje'               => 150.00,
                'es_hora_extra'            => true,
                'aplica_dominical_festivo'  => true,
                'hora_inicio'              => '21:00',
                'hora_fin'                 => '06:00',
                'activo'                   => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ],
        ];

        foreach ($tipos as $tipo) {
            DB::table('humtal_tipos_recargo')->updateOrInsert(
                ['codigo' => $tipo['codigo']],
                $tipo
            );
        }

        // ÔöÇÔöÇÔöÇ PAR+üMETROS DE JORNADA ÔöÇÔöÇÔöÇ
        // Ley 2101 de 2021: reducci+¦n progresiva de jornada m+íxima semanal
        // 2023: 47h ÔåÆ 2024: 46h ÔåÆ 2025: 44h ÔåÆ 2026: 42h
        $jornadas = [
            [
                'horas_max_dia'           => 8.00,
                'horas_max_semana'        => 46.00,
                'horas_max_mes'           => 200.00,
                'jornada_diurna_inicio'   => '06:00',
                'jornada_diurna_fin'      => '21:00',
                'jornada_nocturna_inicio' => '21:00',
                'jornada_nocturna_fin'    => '06:00',
                'vigente_desde'           => '2024-01-01',
                'vigente_hasta'           => '2025-07-14',
                'activo'                  => true,
                'observacion'             => 'Jornada 46h semanales (Ley 2101/2021 - etapa 2024)',
                'created_at'              => now(),
                'updated_at'              => now(),
            ],
            [
                'horas_max_dia'           => 8.00,
                'horas_max_semana'        => 44.00,
                'horas_max_mes'           => 191.00,
                'jornada_diurna_inicio'   => '06:00',
                'jornada_diurna_fin'      => '21:00',
                'jornada_nocturna_inicio' => '21:00',
                'jornada_nocturna_fin'    => '06:00',
                'vigente_desde'           => '2025-07-15',
                'vigente_hasta'           => '2026-07-14',
                'activo'                  => true,
                'observacion'             => 'Jornada 44h semanales (Ley 2101/2021 - etapa 2025)',
                'created_at'              => now(),
                'updated_at'              => now(),
            ],
            [
                'horas_max_dia'           => 8.00,
                'horas_max_semana'        => 42.00,
                'horas_max_mes'           => 182.00,
                'jornada_diurna_inicio'   => '06:00',
                'jornada_diurna_fin'      => '21:00',
                'jornada_nocturna_inicio' => '21:00',
                'jornada_nocturna_fin'    => '06:00',
                'vigente_desde'           => '2026-07-15',
                'vigente_hasta'           => null,
                'activo'                  => true,
                'observacion'             => 'Jornada 42h semanales - meta final (Ley 2101/2021)',
                'created_at'              => now(),
                'updated_at'              => now(),
            ],
        ];

        foreach ($jornadas as $jornada) {
            DB::table('humtal_parametros_jornada')->updateOrInsert(
                ['vigente_desde' => $jornada['vigente_desde']],
                $jornada
            );
        }
    }
}
