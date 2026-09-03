<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserta los tipos de inventario iniciales requeridos:
 *
 *  - Inventario General  → periodicidad anual  (máx. 1 registro por activo por año)
 *  - Inventario Aleatorio → periodicidad mensual (máx. 1 registro por activo por mes)
 *
 * Usa updateOrInsert para ser idempotente: si ya existen los actualiza,
 * si no existen los crea. Nunca genera duplicados.
 */
class InvTiposInventarioSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [
                'nombre'       => 'Inventario General',
                'periodicidad' => 'anual',
                'activo'       => true,
                'descripcion'  => 'Inventario completo realizado una vez al año. Un activo no puede registrarse más de una vez durante el mismo año con este tipo.',
            ],
            [
                'nombre'       => 'Inventario Aleatorio',
                'periodicidad' => 'mensual',
                'activo'       => true,
                'descripcion'  => 'Inventario selectivo realizado mensualmente. Un activo no puede registrarse más de una vez durante el mismo mes con este tipo.',
            ],
        ];

        foreach ($tipos as $tipo) {
            DB::table('inv_tipos_inventario')->updateOrInsert(
                ['nombre' => $tipo['nombre']],   // condición de búsqueda
                array_merge($tipo, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('✓ Tipos de inventario iniciales insertados/actualizados.');
    }
}
