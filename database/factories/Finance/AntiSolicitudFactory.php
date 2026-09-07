<?php

namespace Database\Factories\Finance;

use App\Models\Finance\AntiSolicitud;
use Illuminate\Database\Eloquent\Factories\Factory;

class AntiSolicitudFactory extends Factory
{
    protected $model = AntiSolicitud::class;

    public function definition(): array
    {
        $year = date('Y');
        $consecutivo = $this->faker->unique()->numberBetween(1, 99999);

        return [
            'numero_solicitud' => sprintf('ANT-%s-%05d', $year, $consecutivo),
            // Genera las dependencias necesarias para satisfacer las FKs.
            // id_empleado → config_person_tercero, id_ciudad_destino → anti_ciudades,
            // radicado_por → users. id_sede_origen es nullable, se deja null.
            'id_empleado' => \App\Models\Empleado::factory(),
            'unidad_funcional' => $this->faker->randomNumber(3),
            'id_sede_origen' => null,
            'id_ciudad_destino' => \App\Models\Finance\AntiCiudad::factory(),
            'fecha_salida' => $this->faker->dateTimeBetween('now', '+30 days'),
            'fecha_regreso' => $this->faker->dateTimeBetween('+31 days', '+40 days'),
            'motivo' => $this->faker->sentence(),
            'cobertura' => $this->faker->randomElement(['nacional', 'internacional']),
            'monto_solicitado' => $this->faker->numberBetween(200000, 5000000),
            'monto_autorizado' => null,
            'estado' => 'borrador',
            'radicado_por' => \App\Models\User::factory(),
        ];
    }
}
