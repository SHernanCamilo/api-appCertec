<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sede;
use App\Models\Sucursal;

class SedeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener las sucursales existentes
        $sucursales = Sucursal::all();
        
        if ($sucursales->isEmpty()) {
            $this->command->warn('No hay sucursales en la base de datos. Ejecuta primero SucursalSeeder.');
            return;
        }

        $sedes = [
            // Sedes para Sucursal Norte (id: 1) - CLINICA MEDILASER
            [
                'nombre' => 'Sede Norte - Piso 1',
                'id_Sucursal' => 1
            ],
            [
                'nombre' => 'Sede Norte - Piso 2',
                'id_Sucursal' => 1
            ],
            
            // Sedes para Sucursal Sur (id: 2) - CLINICA MEDILASER
            [
                'nombre' => 'Sede Sur - Principal',
                'id_Sucursal' => 2
            ],
            [
                'nombre' => 'Sede Sur - Anexo',
                'id_Sucursal' => 2
            ],
            
            // Sedes para Sucursal Centro (id: 3) - CLINICA MEDILASER
            [
                'nombre' => 'Sede Centro - Torre A',
                'id_Sucursal' => 3
            ],
            [
                'nombre' => 'Sede Centro - Torre B',
                'id_Sucursal' => 3
            ],
            
            // Sedes para Sede Principal (id: 4) - HOSPITAL SAN JOSE
            [
                'nombre' => 'Consulta Externa',
                'id_Sucursal' => 4
            ],
            [
                'nombre' => 'Urgencias',
                'id_Sucursal' => 4
            ],
            [
                'nombre' => 'Hospitalización',
                'id_Sucursal' => 4
            ],
            
            // Sedes para Sede Occidente (id: 5) - HOSPITAL SAN JOSE
            [
                'nombre' => 'Occidente - Consultorios',
                'id_Sucursal' => 5
            ],
            
            // Sedes para Sucursal Chapinero (id: 6) - CENTRO MEDICO INTEGRAL
            [
                'nombre' => 'Chapinero - Edificio Principal',
                'id_Sucursal' => 6
            ],
            [
                'nombre' => 'Chapinero - Laboratorio',
                'id_Sucursal' => 6
            ],
            
            // Sedes para Sucursal Suba (id: 7) - CENTRO MEDICO INTEGRAL
            [
                'nombre' => 'Suba - Consultorios',
                'id_Sucursal' => 7
            ],
            
            // Sedes para Sucursal Usaquén (id: 8) - CENTRO MEDICO INTEGRAL
            [
                'nombre' => 'Usaquén - Principal',
                'id_Sucursal' => 8
            ],
            [
                'nombre' => 'Usaquén - Imágenes Diagnósticas',
                'id_Sucursal' => 8
            ]
        ];

        foreach ($sedes as $sede) {
            // Verificar que la sucursal existe antes de crear la sede
            if (Sucursal::find($sede['id_Sucursal'])) {
                Sede::create($sede);
            }
        }

        $this->command->info('Sedes creadas exitosamente!');
    }
}
