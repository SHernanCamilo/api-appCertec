<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TurnosDatosMaestrosSeeder extends Seeder
{
    public function run(): void
    {
        // 1. TIPOS DE NOVEDAD
        $novedadTipos = [
            [
                'codigo' => 'VAC',
                'nombre' => 'Vacaciones',
                'descripcion' => 'Período de descanso remunerado',
                'afecta_turno' => true,
                'requiere_reemplazo' => true,
                'requiere_aprobacion' => true,
                'color_hex' => '#2ECC71', // Verde
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'INC',
                'nombre' => 'Incapacidad Médica',
                'descripcion' => 'Ausencia por motivos de salud',
                'afecta_turno' => true,
                'requiere_reemplazo' => true,
                'requiere_aprobacion' => true,
                'color_hex' => '#E74C3C', // Rojo
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'LIC',
                'nombre' => 'Licencia',
                'descripcion' => 'Licencias remuneradas o no remuneradas',
                'afecta_turno' => true,
                'requiere_reemplazo' => true,
                'requiere_aprobacion' => true,
                'color_hex' => '#F1C40F', // Amarillo
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'CAM',
                'nombre' => 'Cambio de Turno',
                'descripcion' => 'Intercambio de turno con otro compañero',
                'afecta_turno' => true,
                'requiere_reemplazo' => true,
                'requiere_aprobacion' => true,
                'color_hex' => '#3498DB', // Azul
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'PER',
                'nombre' => 'Permiso Especial',
                'descripcion' => 'Salidas cortas o permisos por horas',
                'afecta_turno' => false,
                'requiere_reemplazo' => false,
                'requiere_aprobacion' => true,
                'color_hex' => '#9B59B6', // Morado
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($novedadTipos as $tipo) {
            DB::table('humtal_ct_novedad_tipo')->updateOrInsert(
                ['codigo' => $tipo['codigo']],
                $tipo
            );
        }

        // 2. PLANTILLAS DE TURNOS BÁSICAS
        $plantillas = [
            [
                'codigo' => 'T1',
                'nombre' => 'Turno Mañana (6-2)',
                'hora_inicio' => '06:00:00',
                'hora_fin' => '14:00:00',
                'duracion_horas' => 8.0,
                'es_nocturno' => false,
                'color_hex' => '#F39C12', // Naranja
                'id_empresa' => null, // Global
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'T2',
                'nombre' => 'Turno Tarde (2-10)',
                'hora_inicio' => '14:00:00',
                'hora_fin' => '22:00:00',
                'duracion_horas' => 8.0,
                'es_nocturno' => false,
                'color_hex' => '#2980B9', // Azul oscuro
                'id_empresa' => null,
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'T3',
                'nombre' => 'Turno Noche (10-6)',
                'hora_inicio' => '22:00:00',
                'hora_fin' => '06:00:00',
                'duracion_horas' => 8.0,
                'es_nocturno' => true,
                'color_hex' => '#2C3E50', // Gris oscuro
                'id_empresa' => null,
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'ADMIN',
                'nombre' => 'Administrativo (7-5)',
                'hora_inicio' => '07:00:00',
                'hora_fin' => '17:00:00',
                'duracion_horas' => 9.0, // Incluye hora de almuerzo? Depende de la lógica del cliente
                'es_nocturno' => false,
                'color_hex' => '#16A085', // Verde azulado
                'id_empresa' => null,
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($plantillas as $plantilla) {
            DB::table('humtal_ct_plantillas')->updateOrInsert(
                ['codigo' => $plantilla['codigo']],
                $plantilla
            );
        }

        echo "✅ Datos maestros de Turnos (Tipos de Novedad y Plantillas) cargados correctamente.\n";
    }
}
