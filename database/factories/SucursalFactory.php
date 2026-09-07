<?php

namespace Database\Factories;

use App\Models\Sucursal;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

class SucursalFactory extends Factory
{
    protected $model = Sucursal::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->city(),
            'prefijo' => strtoupper($this->faker->lexify('??')),
            'id_Empresa' => Empresa::factory(),
        ];
    }
}
