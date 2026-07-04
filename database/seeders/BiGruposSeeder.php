<?php

namespace Database\Seeders;

use App\Models\BiGrupo;
use Illuminate\Database\Seeder;

class BiGruposSeeder extends Seeder
{
    /**
     * Catálogo de grupos Azure AD → esquemas Fabric.
     * tipo 1 = acceso a esquema (GG-BD-*)
     */
    public function run(): void
    {
        $grupos = [
            ['codigo' => 'GG-BD-IN', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'IN — Inventario'],
            ['codigo' => 'GG-BD-CO', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'CO — Contabilidad'],
            ['codigo' => 'GG-BD-DF', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'DF — Dirección Financiera'],
            ['codigo' => 'GG-BD-AA', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'AA — Asistencial/Agendas médicas'],
            ['codigo' => 'GG-BD-RA', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'RA — Radiología'],
            ['codigo' => 'GG-BD-TH', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'TH — Talento Humano'],
            ['codigo' => 'GG-BD-AU', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'AU — Auditoría'],
            ['codigo' => 'GG-BD-QX', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'QX — Cirugías / Quirófanos'],
            ['codigo' => 'GG-BD-HG', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'HG — Historias Clínicas'],
            ['codigo' => 'GG-BD-CA', 'tipo' => BiGrupo::TIPO_ESQUEMA, 'descripcion' => 'CA — Cartera'],
        ];

        foreach ($grupos as $grupo) {
            BiGrupo::updateOrCreate(
                ['codigo' => $grupo['codigo']],
                [
                    'tipo'        => $grupo['tipo'],
                    'descripcion' => $grupo['descripcion'],
                ]
            );
        }
    }
}
