<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prefijo de consecutivo de Fichas Técnicas por sucursal.
 *
 * En el sistema legacy el número de ficha se generaba con el prefijo de la
 * SUCURSAL (DMC-2024-8, DMA-2023-12, DMN-...), y cada sucursal llevaba su propia
 * secuencia. El sistema nuevo ya tiene sus sucursales con un `prefijo` oficial
 * (NVA, FLA, TJA...) que usan otros módulos.
 *
 * Para no pisar ese prefijo oficial pero preservar la continuidad documental de
 * los consecutivos de fichas, se agrega una columna dedicada `prefijo_fichas`.
 * El servicio de consecutivos de fichas usa esta columna; si está vacía, cae al
 * `prefijo` oficial de la sucursal.
 *
 * Mapeo sucursal (Medilaser, id_empresa=1) → prefijo legacy:
 *   Bogota/CENTRO=DMC, Neiva=DMN, Florencia=DMF, Tunja=DMT, Facatativa=DMK,
 *   Pitalito=DMP, Duitama=DMD, Mocoa=DMM.
 * Sucursales legacy sin equivalente en el sistema nuevo se crean:
 *   ABNER=DMA, MYRIAM PARRA=DMI, NACIONAL=NAL.
 */
return new class extends Migration
{
    private const EMPRESA_MEDILASER = 1;

    /** Sucursales existentes → prefijo de fichas (por coincidencia de nombre). */
    private const PREFIJO_POR_NOMBRE = [
        'Sucursal Bogota'     => 'DMC', // CENTRO en el legacy
        'Sucursal Neiva'      => 'DMN',
        'Sucursal Florencia'  => 'DMF',
        'Sucursal Tunja'      => 'DMT',
        'Sucursal Facatativa' => 'DMK',
        'Sucursal Pitalito'   => 'DMP',
        'Sucursal Duitama'    => 'DMD',
        'Sucursal Mocoa'      => 'DMM',
    ];

    /** Sucursales legacy sin equivalente en el sistema nuevo: [nombre, prefijo_oficial, prefijo_fichas]. */
    private const SUCURSALES_NUEVAS = [
        ['Sucursal Abner Lozano', 'ABN', 'DMA'],
        ['Sucursal Myriam Parra', 'MYP', 'DMI'],
        ['Sucursal Nacional',     'NAL', 'NAL'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('config_ubi_sucursales')) {
            return;
        }

        if (! Schema::hasColumn('config_ubi_sucursales', 'prefijo_fichas')) {
            Schema::table('config_ubi_sucursales', function (Blueprint $table): void {
                $table->string('prefijo_fichas', 10)->nullable()->after('prefijo')
                    ->comment('Prefijo del consecutivo de Fichas Técnicas (heredado del legacy). '
                        .'Si es NULL, se usa el prefijo oficial de la sucursal.');
            });
        }

        $ahora = now();

        // 1. Poblar prefijo_fichas en las sucursales existentes.
        foreach (self::PREFIJO_POR_NOMBRE as $nombre => $prefijoFichas) {
            DB::table('config_ubi_sucursales')
                ->where('id_Empresa', self::EMPRESA_MEDILASER)
                ->where('nombre', $nombre)
                ->update(['prefijo_fichas' => $prefijoFichas, 'updated_at' => $ahora]);
        }

        // 2. Crear las sucursales legacy que no tienen equivalente.
        foreach (self::SUCURSALES_NUEVAS as [$nombre, $prefijoOficial, $prefijoFichas]) {
            $existe = DB::table('config_ubi_sucursales')
                ->where('id_Empresa', self::EMPRESA_MEDILASER)
                ->where('nombre', $nombre)
                ->exists();

            if (! $existe) {
                DB::table('config_ubi_sucursales')->insert([
                    'nombre'         => $nombre,
                    'prefijo'        => $prefijoOficial,
                    'prefijo_fichas' => $prefijoFichas,
                    'id_Empresa'     => self::EMPRESA_MEDILASER,
                    'created_at'     => $ahora,
                    'updated_at'     => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('config_ubi_sucursales')) {
            return;
        }

        // Elimina solo las sucursales creadas por esta migración.
        foreach (self::SUCURSALES_NUEVAS as [$nombre]) {
            DB::table('config_ubi_sucursales')
                ->where('id_Empresa', self::EMPRESA_MEDILASER)
                ->where('nombre', $nombre)
                ->delete();
        }

        if (Schema::hasColumn('config_ubi_sucursales', 'prefijo_fichas')) {
            Schema::table('config_ubi_sucursales', function (Blueprint $table): void {
                $table->dropColumn('prefijo_fichas');
            });
        }
    }
};
