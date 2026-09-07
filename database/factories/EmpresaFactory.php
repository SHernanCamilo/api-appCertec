<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmpresaFactory extends Factory
{
    protected $model = Empresa::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->company(),
            'prefijo' => strtoupper($this->faker->lexify('??')),
            'rep_legal' => $this->faker->name(),
            'cc_rep_legal' => $this->faker->numberBetween(10000000, 99999999),
            'direccion' => $this->faker->streetAddress(),
            'telefono' => $this->faker->numberBetween(6010000000, 6019999999),
            'nit' => $this->faker->numberBetween(800000000, 999999999),
            'estado' => 1,
        ];
    }
}
