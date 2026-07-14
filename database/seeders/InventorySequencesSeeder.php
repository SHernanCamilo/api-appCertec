<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Modulo;
use App\Models\Config\SecSecuencia;
use App\Models\Config\SecDetalle;
use App\Models\Config\SecPatron;
use App\Models\Empresa;
use App\Models\Sucursal;
use Carbon\Carbon;

class InventorySequencesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $empresa = Empresa::first(); // Asumimos que hay al menos una empresa
        
        if (!$empresa) {
            $this->command->error('No hay empresas registradas para asociar las secuencias.');
            return;
        }

        DB::beginTransaction();
        try {
            // 1. Crear el módulo INVENTARIO y submódulos si no existen
            $moduloInventario = Modulo::firstOrCreate(
                ['codigo' => 'INVENTARIO'],
                [
                    'nombre' => 'Inventario',
                    'descripcion' => 'Módulo de inventario',
                    'estado' => 1,
                    'nivel' => 1,
                    'orden' => 10,
                ]
            );

            $procesoPedido = Modulo::firstOrCreate(
                ['codigo' => 'PEDIDO', 'id_modulo_padre' => $moduloInventario->id],
                [
                    'nombre' => 'Pedidos',
                    'descripcion' => 'Gestión de pedidos',
                    'estado' => 1,
                    'nivel' => 2,
                    'orden' => 1,
                ]
            );

            $procesoOrdenCompra = Modulo::firstOrCreate(
                ['codigo' => 'ORDEN_COMPRA', 'id_modulo_padre' => $moduloInventario->id],
                [
                    'nombre' => 'Órdenes de Compra',
                    'descripcion' => 'Gestión de órdenes de compra',
                    'estado' => 1,
                    'nivel' => 2,
                    'orden' => 2,
                ]
            );

            $procesoRecepcion = Modulo::firstOrCreate(
                ['codigo' => 'RECEPCION', 'id_modulo_padre' => $moduloInventario->id],
                [
                    'nombre' => 'Recepción Técnica',
                    'descripcion' => 'Recepción de mercancía',
                    'estado' => 1,
                    'nivel' => 2,
                    'orden' => 3,
                ]
            );

            $sucursales = Sucursal::all();

            if ($sucursales->isEmpty()) {
                // Crear algunas sucursales por defecto si no existen
                $sucursalesData = [
                    ['codigo' => 'BOG', 'nombre' => 'Bogotá', 'empresa_id' => $empresa->id],
                    ['codigo' => 'NVA', 'nombre' => 'Neiva', 'empresa_id' => $empresa->id],
                    ['codigo' => 'FLA', 'nombre' => 'Florencia', 'empresa_id' => $empresa->id],
                    ['codigo' => 'TJA', 'nombre' => 'Tunja', 'empresa_id' => $empresa->id],
                    ['codigo' => 'KTA', 'nombre' => 'Caquetá', 'empresa_id' => $empresa->id],
                    ['codigo' => 'PTO', 'nombre' => 'Putumayo', 'empresa_id' => $empresa->id],
                ];
                
                foreach ($sucursalesData as $sData) {
                    Sucursal::firstOrCreate(['codigo' => $sData['codigo']], $sData);
                }
                $sucursales = Sucursal::all();
            }

            // 2. Crear patrones por cada sucursal
            // Ej: NVA-%Y-### para pedidos, NVA-%Y-###OC para órdenes
            $patrones = [];
            foreach ($sucursales as $sucursal) {
                // Patrón para pedidos y recepciones: Ej FLA-%Y-###
                $patrones[$sucursal->id]['PEDIDO'] = SecPatron::firstOrCreate(
                    ['empresa_id' => $empresa->id, 'nombre' => "INVENTARIO_{$sucursal->codigo}_PEDIDO"],
                    ['patron' => "{$sucursal->codigo}-%Y-###", 'descripcion' => "Patrón pedidos {$sucursal->nombre}"]
                );
                
                $patrones[$sucursal->id]['RECEPCION'] = SecPatron::firstOrCreate(
                    ['empresa_id' => $empresa->id, 'nombre' => "INVENTARIO_{$sucursal->codigo}_RECEPCION"],
                    ['patron' => "{$sucursal->codigo}-%Y-###", 'descripcion' => "Patrón recepciones {$sucursal->nombre}"]
                );

                // Patrón para OC: Ej FLA-%Y-###OC
                $patrones[$sucursal->id]['ORDEN_COMPRA'] = SecPatron::firstOrCreate(
                    ['empresa_id' => $empresa->id, 'nombre' => "INVENTARIO_{$sucursal->codigo}_OC"],
                    ['patron' => "{$sucursal->codigo}-%Y-###OC", 'descripcion' => "Patrón órdenes de compra {$sucursal->nombre}"]
                );
            }

            // 3. Crear cabeceras de secuencia
            $secuencias = [];
            $procesos = [
                'PEDIDO' => $procesoPedido,
                'ORDEN_COMPRA' => $procesoOrdenCompra,
                'RECEPCION' => $procesoRecepcion
            ];

            foreach ($procesos as $tipo => $proceso) {
                $secuencias[$tipo] = SecSecuencia::firstOrCreate(
                    [
                        'empresa_id' => $empresa->id,
                        'modulo_id'  => $moduloInventario->id,
                        'proceso_id' => $proceso->id,
                    ],
                    [
                        'es_manual' => false,
                        'ambito'    => SecSecuencia::AMBITO_SUCURSAL,
                        'rango'     => 3,
                        'estado'    => true,
                    ]
                );
            }

            // 4. Crear detalles por cada sucursal y tipo de secuencia
            foreach ($sucursales as $sucursal) {
                foreach ($procesos as $tipo => $proceso) {
                    SecDetalle::firstOrCreate(
                        [
                            'secuencia_id' => $secuencias[$tipo]->id,
                            'sucursal_id'  => $sucursal->id,
                        ],
                        [
                            'patron_id'        => $patrones[$sucursal->id][$tipo]->id,
                            'siguiente_numero' => 1,
                            'estado'           => true,
                        ]
                    );
                }
            }

            DB::commit();
            $this->command->info('Secuencias de inventario creadas exitosamente.');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Error al crear secuencias: ' . $e->getMessage());
        }
    }
}
