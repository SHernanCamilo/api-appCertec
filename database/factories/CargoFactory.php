<?php

namespace Database\Factories;

use App\Models\Cargo;
use Illuminate\Database\Eloquent\Factories\Factory;

class CargoFactory extends Factory
{
    protected $model = Cargo::class;

    public function definition(): array
    {
        return [
            'nombre_cargo' => $this->faker->jobTitle(),
            'nivel_jerarquico' => $this->faker->numberBetween(1, 3),
            'descripcion' => $this->faker->sentence(),
            'estado' => true,
        ];
    }
}
