<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Empresa;

class EmpresaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $empresas = [
            [
                'nombre' => 'CLINICA MEDILASER',
                'prefijo' => 'MD',
                'rep_legal' => 'Juan Pérez García',
                'cc_rep_legal' => 12345678,
                'direccion' => 'Calle 123 #45-67',
                'telefono' => 3001234567,
                'nit' => 900123456,
                'logo' => 'https://www.medilaser.com.co/assets/images/logo-medilaser.png',
                'estado' => 1
            ],
            [
                'nombre' => 'HOSPITAL SAN JOSE',
                'prefijo' => 'HSJ',
                'rep_legal' => 'María López Rodríguez',
                'cc_rep_legal' => 98765432,
                'direccion' => 'Avenida 45 #12-34',
                'telefono' => 3109876543,
                'nit' => 900987654,
                'logo' => null,
                'estado' => 1
            ],
            [
                'nombre' => 'CENTRO MEDICO INTEGRAL',
                'prefijo' => 'CMI',
                'rep_legal' => 'Carlos Martínez Silva',
                'cc_rep_legal' => 55555555,
                'direccion' => 'Carrera 7 #89-12',
                'telefono' => 3205555555,
                'nit' => 900555555,
                'logo' => null,
                'estado' => 1
            ]
        ];

        foreach ($empresas as $empresa) {
            Empresa::create($empresa);
        }
    }
}
