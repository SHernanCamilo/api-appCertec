<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AllowedDomain;

class AllowedDomainsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domains = [
            [
                'domain' => '@medilaser.com.co',
                'tenant_id' => 'c51cdb8c-7df6-40f1-889c-abece1950a33',
                'tenant_name' => 'Medilaser Tenant',
                'id_empresa' => null, // Asignar después cuando crees la empresa
                'activo' => 1,
                'descripcion' => 'Dominio principal de Medilaser'
            ],
            [
                'domain' => '@outlook.com',
                'tenant_id' => 'common',
                'tenant_name' => 'Microsoft Personal',
                'id_empresa' => null,
                'activo' => 1,
                'descripcion' => 'Cuentas personales de Microsoft para pruebas'
            ],
            [
                'domain' => '@hotmail.com',
                'tenant_id' => 'common',
                'tenant_name' => 'Microsoft Personal',
                'id_empresa' => null,
                'activo' => 1,
                'descripcion' => 'Cuentas personales de Microsoft para pruebas'
            ],
            // Agrega aquí tus otros dominios cuando los tengas
            // [
            //     'domain' => '@tuotrodominio.com',
            //     'tenant_id' => 'otro-tenant-id',
            //     'tenant_name' => 'Otra Empresa',
            //     'id_empresa' => 2,
            //     'activo' => 1,
            //     'descripcion' => 'Segundo tenant'
            // ]
        ];

        foreach ($domains as $domain) {
            AllowedDomain::create($domain);
        }

        $this->command->info('✅ Dominios permitidos creados exitosamente!');
        $this->command->info('📝 Recuerda actualizar los tenant_id con tus IDs reales de Microsoft Azure');
    }
}
