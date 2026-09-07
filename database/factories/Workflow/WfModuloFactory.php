<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WfModulo;
use Illuminate\Database\Eloquent\Factories\Factory;

class WfModuloFactory extends Factory
{
    protected $model = WfModulo::class;

    public function definition(): array
    {
        return [
            'codigo' => $this->faker->unique()->slug(2),
            'nombre' => $this->faker->words(3, true),
            'descripcion' => $this->faker->sentence(),
            'estado' => true,
        ];
    }
}
