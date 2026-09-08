<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Configura el sistema de secuencias (config_sec_*) para el módulo de
 * Fichas Técnicas, para que el consecutivo se genere con el estándar de la
 * plataforma en vez del atajo `config_ubi_sucursales.prefijo_fichas`.
 *
 * Modelo:
 *   config_sec_secuencias  → cabecera (empresa 1, módulo Fichas, ámbito sucursal).
 *   config_sec_patrones    → patrón por sucursal (DMN####, DMF####, …).
 *   config_sec_detalles    → tabla intermedia: sucursal ↔ patrón + siguiente_numero.
 *
 * Formato del consecutivo: {PREFIJO_FICHAS}{####}  → DMN0001, DMF0001, …
 * (secuencial continuo por sucursal; arranca en 1).
 *
 * Solo Medilaser (empresa 1) por ahora. Seguro de re-ejecutar: no duplica.
 *
 * Ejecutar:  php artisan db:seed --class=FichasSecuenciasSeeder
 */
class FichasSecuenciasSeeder extends Seeder
{
    /** Empresa Clínica Medilaser. */
    private const EMPRESA_ID = 1;

    /** Módulo "Fichas Técnicas" en seg_modulos. */
    private const MODULO_ID = 95;

    /** Ancho del número (DMN0001 = 4 dígitos). */
    private const RANGO = 4;

    public function run(): void
    {
        $userId = DB::table('users')->min('id');

        // 1) Cabecera de secuencia (una por empresa+módulo, ámbito sucursal).
        $secuenciaId = DB::table('config_sec_secuencias')
            ->where('empresa_id', self::EMPRESA_ID)
            ->where('modulo_id', self::MODULO_ID)
            ->whereNull('proceso_id')
            ->value('id');

        if ($secuenciaId === null) {
            $secuenciaId = DB::table('config_sec_secuencias')->insertGetId([
                'empresa_id'    => self::EMPRESA_ID,
                'modulo_id'     => self::MODULO_ID,
                'proceso_id'    => null,
                'es_manual'     => 0,
                'ambito'        => 'sucursal',
                'es_secuencial' => 1,
                'rango'         => self::RANGO,
                'estado'        => 1,
                'created_by'    => $userId,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // 2) Un patrón + un detalle por cada sucursal de Medilaser que tenga
        //    prefijo_fichas configurado.
        $sucursales = DB::table('config_ubi_sucursales')
            ->where('id_Empresa', self::EMPRESA_ID)
            ->whereNotNull('prefijo_fichas')
            ->where('prefijo_fichas', '<>', '')
            ->get(['id', 'nombre', 'prefijo_fichas']);

        foreach ($sucursales as $suc) {
            $prefijo = strtoupper(trim($suc->prefijo_fichas));
            // Patrón: PREFIJO + '#'*RANGO  → 'DMN####'  → DMN0001
            $patronTexto = $prefijo.str_repeat('#', self::RANGO);

            // Patrón (idempotente por empresa+patron).
            $patronId = DB::table('config_sec_patrones')
                ->where('empresa_id', self::EMPRESA_ID)
                ->where('patron', $patronTexto)
                ->value('id');

            if ($patronId === null) {
                $patronId = DB::table('config_sec_patrones')->insertGetId([
                    'empresa_id'  => self::EMPRESA_ID,
                    'nombre'      => 'FICHA '.$prefijo,
                    'patron'      => $patronTexto,
                    'descripcion' => "Consecutivo de ficha técnica — {$suc->nombre}",
                    'estado'      => 1,
                    'created_by'  => $userId,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            // Detalle (idempotente por secuencia+sucursal).
            $existeDetalle = DB::table('config_sec_detalles')
                ->where('secuencia_id', $secuenciaId)
                ->where('sucursal_id', $suc->id)
                ->exists();

            if (! $existeDetalle) {
                DB::table('config_sec_detalles')->insert([
                    'secuencia_id'     => $secuenciaId,
                    'patron_id'        => $patronId,
                    'sucursal_id'      => $suc->id,
                    'sede_id'          => null,
                    'siguiente_numero' => 1,
                    'estado'           => 1,
                    'created_by'       => $userId,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        $this->command?->info('✓ Secuencias de Fichas Técnicas configuradas para '.count($sucursales).' sucursales (Medilaser).');
    }
}
