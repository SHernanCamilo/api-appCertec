<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WfInstancia;
use Illuminate\Database\Eloquent\Factories\Factory;

class WfInstanciaFactory extends Factory
{
    protected $model = WfInstancia::class;

    public function definition(): array
    {
        return [
            'id_definicion' => 1,
            'id_modulo' => 1,
            'modulo_record_id' => $this->faker->randomNumber(5),
            'solicitante_id' => 1,
            'contexto' => ['record_id' => $this->faker->randomNumber(5)],
            'consecutivo' => 'ANT-2026-' . $this->faker->unique()->numerify('#####'),
            'id_paso_actual' => 1,
            'estado' => WfInstancia::ESTADO_EN_PROGRESO,
        ];
    }
}
