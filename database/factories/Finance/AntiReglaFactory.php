<?php

namespace Database\Factories\Finance;

use App\Models\Finance\AntiRegla;
use Illuminate\Database\Eloquent\Factories\Factory;

class AntiReglaFactory extends Factory
{
    protected $model = AntiRegla::class;

    public function definition(): array
    {
        return [
            'id_concepto' => 1,
            'nivel_jerarquico' => $this->faker->numberBetween(0, 3),
            'descripcion' => $this->faker->word(),
            'valor_tope' => $this->faker->numberBetween(20000, 100000),
            'estado' => true,
        ];
    }
}
