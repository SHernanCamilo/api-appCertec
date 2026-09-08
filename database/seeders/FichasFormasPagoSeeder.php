<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra las formas de pago (plazos) parametrizables del módulo Fichas Técnicas.
 *
 * Seguro de re-ejecutar: insertOrIgnore no duplica registros.
 *
 * Ejecutar:
 *   php artisan db:seed --class=FichasFormasPagoSeeder
 */
class FichasFormasPagoSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['id' => 1, 'descripcion' => '30 DÍAS HÁBILES',    'dias' => 30,  'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'descripcion' => '45 DÍAS HÁBILES',    'dias' => 45,  'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'descripcion' => '60 DÍAS HÁBILES',    'dias' => 60,  'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'descripcion' => '90 DÍAS HÁBILES',    'dias' => 90,  'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'descripcion' => '120 DÍAS HÁBILES',   'dias' => 120, 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'descripcion' => '30 DÍAS CALENDARIO', 'dias' => 30,  'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'descripcion' => '90 DÍAS CALENDARIO', 'dias' => 90,  'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('fich_formas_pago')->insertOrIgnore($rows);

        $this->command?->info('✓ Formas de pago sembradas ('.count($rows).' registros).');
    }
}
