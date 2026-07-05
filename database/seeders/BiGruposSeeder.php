<?php

namespace Database\Seeders;

use App\Models\BiGrupo;
use Illuminate\Database\Seeder;

class BiGruposSeeder extends Seeder
{
    /**
     * Catálogo esquema Fabric → tipo de reporte.
     * Códigos cortos (AA, CO…) — users_grups usa GG-BD-{codigo}.
     *
     * tipo 1 = asistenciales · 2 = financieros · 3 = administrativos
     */
    public function run(): void
    {
        $grupos = [
            ['codigo' => 'AA', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'AA — Atención Ambulatoria'],
            ['codigo' => 'CA', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'CA — Cartera'],
            ['codigo' => 'CO', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'CO — Contabilidad'],
            ['codigo' => 'CP', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'CP — Costos y Presupuestos'],
            ['codigo' => 'DC', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'DC — Datos Comunes'],
            ['codigo' => 'DF', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'DF — Dirección Financiera'],
            ['codigo' => 'DT', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'DT — Apoyo Diagnóstico y Terapéutico'],
            ['codigo' => 'EX', 'tipo' => BiGrupo::TIPO_ADMINISTRATIVO, 'descripcion' => 'EX — Expedientes'],
            ['codigo' => 'FR', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'FR — Facturación y Radicación'],
            ['codigo' => 'GD', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'GD — Glosas y Devolución'],
            ['codigo' => 'HG', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'HG — Historias Clínicas'],
            ['codigo' => 'IN', 'tipo' => BiGrupo::TIPO_ADMINISTRATIVO, 'descripcion' => 'IN — Inventarios'],
            ['codigo' => 'NO', 'tipo' => BiGrupo::TIPO_FINANCIERO, 'descripcion' => 'NO — Nómina'],
            ['codigo' => 'PC', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'PC — Promoción y Prevención'],
            ['codigo' => 'PT', 'tipo' => BiGrupo::TIPO_ADMINISTRATIVO, 'descripcion' => 'PT — Pagos y Tesorería'],
            ['codigo' => 'QX', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'QX — Cirugías / Quirófanos'],
            ['codigo' => 'RA', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'RA — Radiología'],
            ['codigo' => 'RF', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'RF — Referencia y Contrarreferencia'],
            ['codigo' => 'UG', 'tipo' => BiGrupo::TIPO_ASISTENCIAL, 'descripcion' => 'UG — Urgencias'],
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
