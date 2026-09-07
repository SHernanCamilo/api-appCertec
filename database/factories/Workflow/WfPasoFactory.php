<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WfPaso;
use Illuminate\Database\Eloquent\Factories\Factory;

class WfPasoFactory extends Factory
{
    protected $model = WfPaso::class;

    public function definition(): array
    {
        return [
            'id_definicion' => 1,
            'orden' => $this->faker->numberBetween(1, 5),
            'nombre_paso' => $this->faker->words(3, true),
            'rol_aprobador' => $this->faker->randomElement(['jefe_inmediato', 'financiero', 'tesoreria', 'vicepresidente']),
            'es_opcional' => false,
            'permite_rechazo' => true,
            'requiere_monto' => false,
            'reglas' => null,
            'descripcion_contexto' => $this->faker->sentence(),
            'estado' => true,
        ];
    }
}
