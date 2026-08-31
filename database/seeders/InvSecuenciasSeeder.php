<?php

namespace Database\Seeders;

use App\Models\Config\SecSecuencia;
use App\Models\Config\SecPatron;
use App\Models\Config\SecDetalle;
use App\Models\Modulo;
use App\Models\Sucursal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parametriza las secuencias numéricas del módulo de Inventario POR SUCURSAL.
 *
 * Crea, de forma idempotente:
 *   1. Los procesos (submódulos) del módulo Inventario en seg_modulos:
 *      INV-ORDEN_COMPRA, INV-PEDIDO, INV-RECEPCION (hijos del módulo 'INV').
 *   2. Un patrón por sucursal en config_sec_patrones con su prefijo
 *      (ej. 'FLA-%Y-######' → FLA-2026-000001).
 *   3. Una cabecera config_sec_secuencias (ámbito 'sucursal') por cada proceso.
 *   4. Un config_sec_detalles por (secuencia × sucursal) con su patrón y contador.
 *
 * Objetivo: que una OC/pedido/recepción se numere según la sucursal elegida,
 * evitando que una compra de Neiva quede con el consecutivo de Florencia.
 *
 * Ejecutar:  php artisan db:seed --class=InvSecuenciasSeeder
 * Idempotente: puede correrse varias veces sin duplicar.
 */
class InvSecuenciasSeeder extends Seeder
{
    /** Empresa a parametrizar. Ajustable si se requiere otra. */
    private const EMPRESA_ID = 1;

    /** Código del módulo padre de inventario en seg_modulos. */
    private const MODULO_INV_CODIGO = 'INV';

    /** Dígitos del consecutivo (padding). */
    private const RANGO = 6;

    /**
     * Prefijos conocidos por nombre de sucursal (para las que aún no tienen
     * 'prefijo' cargado en config_ubi_sucursales). Se usa como respaldo.
     */
    private const PREFIJOS_POR_NOMBRE = [
        'neiva'        => 'NVA',
        'florencia'    => 'FLA',
        'tunja'        => 'TJA',
        'facatativa'   => 'KTA',
        'bogota'       => 'BOG',
        'bogotá'       => 'BOG',
        'mocoa'        => 'MOC',
        'duitama'      => 'DTA',
        'pitalito'     => 'PTO',
        'villavicencio'=> 'VVC',
        'yopal'        => 'YOP',
        'meta'         => 'MET',
        'casanare'     => 'CAS',
        'boyaca'       => 'BOY',
    ];

    /** Procesos de inventario a parametrizar: código → nombre legible. */
    private const PROCESOS = [
        'INV-ORDEN_COMPRA' => 'Órdenes de Compra',
        'INV-PEDIDO'       => 'Pedidos',
        'INV-RECEPCION'    => 'Recepciones Técnicas',
    ];

    /**
     * Tabla y columna de número por proceso, para calcular el consecutivo inicial
     * y no chocar con documentos históricos (ej. FLA-2026-000178 ya existe).
     */
    private const FUENTE_CONSECUTIVO = [
        'INV-ORDEN_COMPRA' => ['tabla' => 'inv_ordenes_compra', 'columna' => 'numero_orden_compra'],
        'INV-PEDIDO'       => ['tabla' => 'inv_pedidos',        'columna' => 'numero_pedido'],
        'INV-RECEPCION'    => ['tabla' => 'inv_recepciones',    'columna' => 'numero_recepcion'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $modInv = Modulo::where('codigo', self::MODULO_INV_CODIGO)->first();
            if (!$modInv) {
                $this->command?->error("No existe el módulo con código '" . self::MODULO_INV_CODIGO . "' en seg_modulos. Aborto.");
                return;
            }

            // 1. Procesos (submódulos) del módulo Inventario.
            $procesoIds = [];
            foreach (self::PROCESOS as $codigo => $nombre) {
                $proceso = Modulo::firstOrCreate(
                    ['codigo' => $codigo],
                    [
                        'id_modulo_padre' => $modInv->id,
                        'nombre'          => Str::limit($nombre, 50, ''),
                        'descripcion'     => "Proceso de inventario: {$nombre}",
                        'orden'           => 0,
                        'nivel'           => ($modInv->nivel ?? 0) + 1,
                        'estado'          => 1,
                    ]
                );
                $procesoIds[$codigo] = $proceso->id;
            }

            // 2. Sucursales de la empresa.
            $sucursales = Sucursal::where('id_Empresa', self::EMPRESA_ID)->orderBy('id')->get();
            if ($sucursales->isEmpty()) {
                $this->command?->warn('La empresa ' . self::EMPRESA_ID . ' no tiene sucursales. Nada que parametrizar.');
                return;
            }

            // 3. Patrón por sucursal (uno reutilizable entre los procesos de esa sucursal).
            $patronPorSucursal = [];
            $prefijoPorSucursal = [];
            foreach ($sucursales as $suc) {
                $prefijo = $this->resolvePrefijo($suc);
                $prefijoPorSucursal[$suc->id] = $prefijo;

                // El prefijo de la sucursal es la fuente de verdad que usan
                // MonitoringService y BranchAccessService para nombrar/identificar OC.
                // Se sincroniza con el prefijo oficial si está vacío o desalineado.
                if (strtoupper(trim((string) $suc->prefijo)) !== $prefijo) {
                    $suc->update(['prefijo' => $prefijo]);
                }

                $patronStr = "{$prefijo}-%Y-" . str_repeat('#', self::RANGO); // ej. FLA-%Y-######

                $patron = SecPatron::firstOrCreate(
                    ['empresa_id' => self::EMPRESA_ID, 'nombre' => "INV {$prefijo}"],
                    [
                        'patron'      => $patronStr,
                        'descripcion' => "Patrón de inventario para sucursal {$suc->nombre} ({$prefijo})",
                        'estado'      => true,
                    ]
                );
                // Mantener el patrón alineado si cambió el prefijo/rango.
                if ($patron->patron !== $patronStr) {
                    $patron->update(['patron' => $patronStr]);
                }
                $patronPorSucursal[$suc->id] = $patron->id;
            }

            // 4. Cabecera de secuencia (ámbito sucursal) por proceso + detalle por sucursal.
            foreach (self::PROCESOS as $codigo => $nombre) {
                $procesoId = $procesoIds[$codigo];

                $secuencia = SecSecuencia::firstOrCreate(
                    [
                        'empresa_id' => self::EMPRESA_ID,
                        'modulo_id'  => $modInv->id,
                        'proceso_id' => $procesoId,
                    ],
                    [
                        'es_manual'     => false,
                        'ambito'        => SecSecuencia::AMBITO_SUCURSAL,
                        'es_secuencial' => true,
                        'rango'         => self::RANGO,
                        'estado'        => true,
                    ]
                );

                // Asegurar ámbito sucursal aunque la fila ya existiera.
                if ($secuencia->ambito !== SecSecuencia::AMBITO_SUCURSAL) {
                    $secuencia->update(['ambito' => SecSecuencia::AMBITO_SUCURSAL, 'rango' => self::RANGO]);
                }

                foreach ($sucursales as $suc) {
                    $inicio = $this->calcularConsecutivoInicial($codigo, $prefijoPorSucursal[$suc->id]);

                    SecDetalle::firstOrCreate(
                        [
                            'secuencia_id' => $secuencia->id,
                            'sucursal_id'  => $suc->id,
                        ],
                        [
                            'patron_id'        => $patronPorSucursal[$suc->id],
                            'sede_id'          => null,
                            'siguiente_numero' => $inicio,
                            'estado'           => true,
                        ]
                    );
                }
            }

            $this->command?->info('Secuencias de inventario parametrizadas por sucursal para empresa ' . self::EMPRESA_ID . '.');
            $this->command?->info('Procesos: ' . implode(', ', array_keys(self::PROCESOS)));
            $this->command?->info('Sucursales: ' . $sucursales->count());
        });
    }

    /**
     * Calcula el consecutivo inicial para un proceso+prefijo, mirando el máximo
     * número ya usado en la tabla del proceso para ese prefijo y año actual.
     * Devuelve max + 1 (o 1 si no hay históricos), evitando colisiones con los
     * documentos que ya existen (p.ej. FLA-2026-000178 → arranca en 179).
     */
    private function calcularConsecutivoInicial(string $codigoProceso, string $prefijo): int
    {
        $fuente = self::FUENTE_CONSECUTIVO[$codigoProceso] ?? null;
        if (!$fuente) {
            return 1;
        }

        $tabla   = $fuente['tabla'];
        $columna = $fuente['columna'];
        $anio    = date('Y');
        $like    = "{$prefijo}-{$anio}-%";

        if (!\Illuminate\Support\Facades\Schema::hasTable($tabla)
            || !\Illuminate\Support\Facades\Schema::hasColumn($tabla, $columna)) {
            return 1;
        }

        $numeros = DB::table($tabla)
            ->where($columna, 'LIKE', $like)
            ->pluck($columna);

        $max = 0;
        foreach ($numeros as $num) {
            // Formato PREFIJO-AÑO-###### (posiblemente con sufijo, ej. -OC)
            if (preg_match('/^' . preg_quote($prefijo, '/') . '-' . $anio . '-(\d+)/', (string) $num, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    /**
     * Resuelve el prefijo de una sucursal: usa el de la BD si existe,
     * si no, deriva por nombre; como último recurso, las primeras 3 letras.
     */
    private function resolvePrefijo(Sucursal $suc): string
    {
        // El mapa oficial (definido por el negocio) tiene PRIORIDAD sobre el valor
        // en BD, para garantizar que las sucursales conocidas queden con el prefijo
        // exacto acordado aunque la BD tenga uno vacío o desalineado.
        $nombre = Str::of($suc->nombre)->lower()->replace('sucursal', '')->trim()->__toString();
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre));

        if (isset(self::PREFIJOS_POR_NOMBRE[$nombre])) {
            return self::PREFIJOS_POR_NOMBRE[$nombre];
        }

        // Coincidencia parcial (ej. "bogotá d.c." → bogota).
        foreach (self::PREFIJOS_POR_NOMBRE as $clave => $pref) {
            if (str_contains($nombre, $clave)) {
                return $pref;
            }
        }

        // Si no está en el mapa, respetar el prefijo ya cargado en BD.
        $prefijoBd = strtoupper(trim((string) $suc->prefijo));
        if ($prefijoBd !== '') {
            return $prefijoBd;
        }

        // Último recurso: 3 primeras letras del nombre en mayúscula.
        return strtoupper(Str::of($nombre)->replace(' ', '')->substr(0, 3)->__toString() ?: 'GEN');
    }
}
