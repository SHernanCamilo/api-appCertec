<?php

namespace Database\Factories;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmpleadoFactory extends Factory
{
    protected $model = Empleado::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'numero_identificacion' => $this->faker->unique()->numerify('##########'),
            'tipo_identificacion' => 'CC',
            // Ambas columnas son nullable con FK; se dejan null por defecto para no
            // depender de registros de ent_empresas/config_cargo inexistentes.
            // Los tests que necesitan un cargo específico lo inyectan explícitamente.
            'id_empresa' => null,
            'id_cargo' => null,
            'unidad' => $this->faker->randomNumber(3),
            'estado' => true,
        ];
    }
}
