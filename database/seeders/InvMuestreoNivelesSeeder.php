<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed de niveles de inspección ISO 2859-1 (Nivel II - General).
 * Datos estándar internacionales para muestreo en recepción técnica farmacéutica.
 */
class InvMuestreoNivelesSeeder extends Seeder
{
    public function run(): void
    {
        $niveles = [
            // Nivel II (General) — el más usado en farmacéutica
            ['nivel_inspeccion' => 'II', 'lote_min' => 2,    'lote_max' => 8,     'letra_codigo' => 'A', 'tamano_muestra' => 2],
            ['nivel_inspeccion' => 'II', 'lote_min' => 9,    'lote_max' => 15,    'letra_codigo' => 'B', 'tamano_muestra' => 3],
            ['nivel_inspeccion' => 'II', 'lote_min' => 16,   'lote_max' => 25,    'letra_codigo' => 'C', 'tamano_muestra' => 5],
            ['nivel_inspeccion' => 'II', 'lote_min' => 26,   'lote_max' => 50,    'letra_codigo' => 'D', 'tamano_muestra' => 8],
            ['nivel_inspeccion' => 'II', 'lote_min' => 51,   'lote_max' => 90,    'letra_codigo' => 'E', 'tamano_muestra' => 13],
            ['nivel_inspeccion' => 'II', 'lote_min' => 91,   'lote_max' => 150,   'letra_codigo' => 'F', 'tamano_muestra' => 20],
            ['nivel_inspeccion' => 'II', 'lote_min' => 151,  'lote_max' => 280,   'letra_codigo' => 'G', 'tamano_muestra' => 32],
            ['nivel_inspeccion' => 'II', 'lote_min' => 281,  'lote_max' => 500,   'letra_codigo' => 'H', 'tamano_muestra' => 50],
            ['nivel_inspeccion' => 'II', 'lote_min' => 501,  'lote_max' => 1200,  'letra_codigo' => 'J', 'tamano_muestra' => 80],
            ['nivel_inspeccion' => 'II', 'lote_min' => 1201, 'lote_max' => 3200,  'letra_codigo' => 'K', 'tamano_muestra' => 125],
            ['nivel_inspeccion' => 'II', 'lote_min' => 3201, 'lote_max' => 10000, 'letra_codigo' => 'L', 'tamano_muestra' => 200],
            ['nivel_inspeccion' => 'II', 'lote_min' => 10001,'lote_max' => 35000, 'letra_codigo' => 'M', 'tamano_muestra' => 315],
            ['nivel_inspeccion' => 'II', 'lote_min' => 35001,'lote_max' => 150000,'letra_codigo' => 'N', 'tamano_muestra' => 500],
            ['nivel_inspeccion' => 'II', 'lote_min' => 150001,'lote_max' => 500000,'letra_codigo' => 'P', 'tamano_muestra' => 800],
            ['nivel_inspeccion' => 'II', 'lote_min' => 500001,'lote_max' => 9999999,'letra_codigo' => 'Q', 'tamano_muestra' => 1250],
        ];

        DB::table('inv_muestreo_niveles')->insertOrIgnore(
            array_map(fn($n) => array_merge($n, ['activo' => true]), $niveles)
        );
    }
}
