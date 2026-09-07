<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WfDefinicion;
use Illuminate\Database\Eloquent\Factories\Factory;

class WfDefinicionFactory extends Factory
{
    protected $model = WfDefinicion::class;

    public function definition(): array
    {
        return [
            'codigo' => $this->faker->unique()->slug(3),
            'nombre' => $this->faker->words(4, true),
            'descripcion' => $this->faker->sentence(),
            'id_modulo' => 1,
            'id_empresa' => null,
            'estado' => true,
        ];
    }
}
