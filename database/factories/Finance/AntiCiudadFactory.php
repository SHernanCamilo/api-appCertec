<?php

namespace Database\Factories\Finance;

use App\Models\Finance\AntiCiudad;
use Illuminate\Database\Eloquent\Factories\Factory;

class AntiCiudadFactory extends Factory
{
    protected $model = AntiCiudad::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->city(),
            'departamento' => $this->faker->state(),
            'tipo_ciudad' => $this->faker->randomElement(['A', 'B', 'C']),
            'estado' => true,
        ];
    }
}
