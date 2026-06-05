<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder de festivos colombianos para los años 2025, 2026 y 2027.
 * Fuente: calendario oficial Colombia (Ley 51 de 1983 — Ley Emiliani).
 */
class FestivosColombiaSeeder extends Seeder
{
    public function run(): void
    {
        $festivos = [
            // ===== 2025 =====
            ['fecha' => '2025-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2025-01-06', 'nombre' => 'Día de los Reyes Magos'],
            ['fecha' => '2025-03-24', 'nombre' => 'Día de San José'],
            ['fecha' => '2025-04-17', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2025-04-18', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2025-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2025-06-02', 'nombre' => 'Día de la Ascensión'],
            ['fecha' => '2025-06-23', 'nombre' => 'Corpus Christi'],
            ['fecha' => '2025-06-30', 'nombre' => 'Sagrado Corazón'],
            ['fecha' => '2025-07-20', 'nombre' => 'Día de la Independencia'],
            ['fecha' => '2025-08-07', 'nombre' => 'Batalla de Boyacá'],
            ['fecha' => '2025-08-18', 'nombre' => 'Asunción de la Virgen'],
            ['fecha' => '2025-10-13', 'nombre' => 'Día de la Raza'],
            ['fecha' => '2025-11-03', 'nombre' => 'Todos los Santos'],
            ['fecha' => '2025-11-17', 'nombre' => 'Independencia de Cartagena'],
            ['fecha' => '2025-12-08', 'nombre' => 'Día de la Inmaculada Concepción'],
            ['fecha' => '2025-12-25', 'nombre' => 'Navidad'],

            // ===== 2026 =====
            ['fecha' => '2026-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2026-01-12', 'nombre' => 'Día de los Reyes Magos'],
            ['fecha' => '2026-03-23', 'nombre' => 'Día de San José'],
            ['fecha' => '2026-04-02', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2026-04-03', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2026-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2026-05-18', 'nombre' => 'Día de la Ascensión'],
            ['fecha' => '2026-06-08', 'nombre' => 'Corpus Christi'],
            ['fecha' => '2026-06-15', 'nombre' => 'Sagrado Corazón'],
            ['fecha' => '2026-06-29', 'nombre' => 'San Pedro y San Pablo'],
            ['fecha' => '2026-07-20', 'nombre' => 'Día de la Independencia'],
            ['fecha' => '2026-08-07', 'nombre' => 'Batalla de Boyacá'],
            ['fecha' => '2026-08-17', 'nombre' => 'Asunción de la Virgen'],
            ['fecha' => '2026-10-12', 'nombre' => 'Día de la Raza'],
            ['fecha' => '2026-11-02', 'nombre' => 'Todos los Santos'],
            ['fecha' => '2026-11-16', 'nombre' => 'Independencia de Cartagena'],
            ['fecha' => '2026-12-08', 'nombre' => 'Día de la Inmaculada Concepción'],
            ['fecha' => '2026-12-25', 'nombre' => 'Navidad'],

            // ===== 2027 =====
            ['fecha' => '2027-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2027-01-11', 'nombre' => 'Día de los Reyes Magos'],
            ['fecha' => '2027-03-22', 'nombre' => 'Día de San José'],
            ['fecha' => '2027-03-25', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2027-03-26', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2027-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2027-05-10', 'nombre' => 'Día de la Ascensión'],
            ['fecha' => '2027-05-31', 'nombre' => 'Corpus Christi'],
            ['fecha' => '2027-06-07', 'nombre' => 'Sagrado Corazón'],
            ['fecha' => '2027-07-05', 'nombre' => 'San Pedro y San Pablo'],
            ['fecha' => '2027-07-20', 'nombre' => 'Día de la Independencia'],
            ['fecha' => '2027-08-16', 'nombre' => 'Asunción de la Virgen'],
            ['fecha' => '2027-10-18', 'nombre' => 'Día de la Raza'],
            ['fecha' => '2027-11-01', 'nombre' => 'Todos los Santos'],
            ['fecha' => '2027-11-15', 'nombre' => 'Independencia de Cartagena'],
            ['fecha' => '2027-12-08', 'nombre' => 'Día de la Inmaculada Concepción'],
            ['fecha' => '2027-12-25', 'nombre' => 'Navidad'],
        ];

        $now = now();
        foreach ($festivos as $f) {
            DB::table('humtal_ct_festivos')->updateOrInsert(
                ['fecha' => $f['fecha']],
                [
                    'nombre'     => $f['nombre'],
                    'estado'     => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
