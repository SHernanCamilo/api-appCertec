<?php

namespace Database\Factories\Finance;

use App\Models\Finance\AntiSolicitudDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

class AntiSolicitudDocumentoFactory extends Factory
{
    protected $model = AntiSolicitudDocumento::class;

    public function definition(): array
    {
        return [
            // Genera las dependencias para satisfacer las FKs:
            // id_solicitud → anti_solicitudes, subido_por → users.
            'id_solicitud' => \App\Models\Finance\AntiSolicitud::factory(),
            'tipo_documento' => $this->faker->randomElement(['soporte_viaje', 'factura', 'recibo', 'otro']),
            'nombre_archivo' => $this->faker->word() . '.pdf',
            'ruta_archivo' => 'anticipos/ANT-2026-00001/' . $this->faker->uuid() . '.pdf',
            'disco' => 'onedrive',
            'mime_type' => 'application/pdf',
            'tamano' => $this->faker->numberBetween(10000, 5000000),
            'subido_por' => \App\Models\User::factory(),
        ];
    }
}
