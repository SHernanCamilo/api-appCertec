<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WfRegla;
use Illuminate\Database\Eloquent\Factories\Factory;

class WfReglaFactory extends Factory
{
    protected $model = WfRegla::class;

    public function definition(): array
    {
        return [
            'id_definicion' => 1,
            'prioridad' => $this->faker->numberBetween(1, 50),
            'condiciones' => ['cobertura' => 'nacional'],
            'estado' => true,
        ];
    }
}
