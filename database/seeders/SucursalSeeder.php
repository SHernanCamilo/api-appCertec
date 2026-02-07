<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sucursal;
use App\Models\Empresa;

class SucursalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener las empresas existentes
        $empresas = Empresa::all();
        
        if ($empresas->isEmpty()) {
            $this->command->warn('No hay empresas en la base de datos. Ejecuta primero EmpresaSeeder.');
            return;
        }

        $sucursales = [
            // Sucursales para CLINICA MEDILASER (id: 1)
            [
                'nombre' => 'Sucursal Norte',
                'id_Empresa' => 1
            ],
            [
                'nombre' => 'Sucursal Sur',
                'id_Empresa' => 1
            ],
            [
                'nombre' => 'Sucursal Centro',
                'id_Empresa' => 1
            ],
            
            // Sucursales para HOSPITAL SAN JOSE (id: 2)
            [
                'nombre' => 'Sede Principal',
                'id_Empresa' => 2
            ],
            [
                'nombre' => 'Sede Occidente',
                'id_Empresa' => 2
            ],
            
            // Sucursales para CENTRO MEDICO INTEGRAL (id: 3)
            [
                'nombre' => 'Sucursal Chapinero',
                'id_Empresa' => 3
            ],
            [
                'nombre' => 'Sucursal Suba',
                'id_Empresa' => 3
            ],
            [
                'nombre' => 'Sucursal Usaquén',
                'id_Empresa' => 3
            ]
        ];

        foreach ($sucursales as $sucursal) {
            // Verificar que la empresa existe antes de crear la sucursal
            if (Empresa::find($sucursal['id_Empresa'])) {
                Sucursal::create($sucursal);
            }
        }

        $this->command->info('Sucursales creadas exitosamente!');
    }
}
