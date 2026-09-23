<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TalentoHumano\CuadroTurnos\CtFestivo;

/**
 * Seeder de festivos oficiales de Colombia.
 *
 * Puebla la tabla humtal_ct_festivos sin depender de la API externa
 * (festivos.com.co). Util para servidores donde no esta configurada
 * FESTIVOS_API_KEY o no hay salida a internet.
 *
 * Es idempotente: usa updateOrCreate, asi que se puede correr varias veces
 * sin duplicar registros.
 *
 * Uso:
 *   php artisan db:seed --class=FestivosColombiaSeeder
 */
class FestivosColombiaSeeder extends Seeder
{
    public function run(): void
    {
        echo "Sembrando festivos oficiales de Colombia...\n";

        $festivos = array_merge(
            $this->festivos2026(),
            $this->festivos2027()
        );

        $creados = 0;
        $actualizados = 0;

        foreach ($festivos as $f) {
            $registro = CtFestivo::updateOrCreate(
                ['fecha' => $f['fecha']],
                [
                    'nombre'      => $f['nombre'],
                    'descripcion' => $f['nombre'],
                    'estado'      => true,
                ]
            );
            $registro->wasRecentlyCreated ? $creados++ : $actualizados++;
        }

        echo "Festivos creados: {$creados} | actualizados: {$actualizados}\n";
        echo "Total procesados: " . count($festivos) . "\n";
    }

    /**
     * Festivos oficiales de Colombia 2026.
     */
    private function festivos2026(): array
    {
        return [
            ['fecha' => '2026-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2026-01-12', 'nombre' => 'Día de los Reyes Magos'],
            ['fecha' => '2026-03-23', 'nombre' => 'Día de San José'],
            ['fecha' => '2026-03-29', 'nombre' => 'Domingo de Ramos'],
            ['fecha' => '2026-04-02', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2026-04-03', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2026-04-05', 'nombre' => 'Domingo de Resurrección'],
            ['fecha' => '2026-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2026-05-18', 'nombre' => 'Día de la Ascensión'],
            ['fecha' => '2026-06-08', 'nombre' => 'Corpus Christi'],
            ['fecha' => '2026-06-15', 'nombre' => 'Sagrado Corazón de Jesús'],
            ['fecha' => '2026-06-29', 'nombre' => 'San Pedro y San Pablo'],
            ['fecha' => '2026-07-20', 'nombre' => 'Día de la Independencia'],
            ['fecha' => '2026-08-07', 'nombre' => 'Batalla de Boyacá'],
            ['fecha' => '2026-08-17', 'nombre' => 'La Asunción de la Virgen'],
            ['fecha' => '2026-10-12', 'nombre' => 'Día de la Raza'],
            ['fecha' => '2026-11-02', 'nombre' => 'Día de Todos los Santos'],
            ['fecha' => '2026-11-16', 'nombre' => 'Independencia de Cartagena'],
            ['fecha' => '2026-12-08', 'nombre' => 'Día de la Inmaculada Concepción'],
            ['fecha' => '2026-12-25', 'nombre' => 'Día de Navidad'],
        ];
    }

    /**
     * Festivos oficiales de Colombia 2027.
     */
    private function festivos2027(): array
    {
        return [
            ['fecha' => '2027-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2027-01-11', 'nombre' => 'Día de los Reyes Magos'],
            ['fecha' => '2027-03-22', 'nombre' => 'Día de San José'],
            ['fecha' => '2027-03-21', 'nombre' => 'Domingo de Ramos'],
            ['fecha' => '2027-03-25', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2027-03-26', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2027-03-28', 'nombre' => 'Domingo de Resurrección'],
            ['fecha' => '2027-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2027-05-10', 'nombre' => 'Día de la Ascensión'],
            ['fecha' => '2027-05-31', 'nombre' => 'Corpus Christi'],
            ['fecha' => '2027-06-07', 'nombre' => 'Sagrado Corazón de Jesús'],
            ['fecha' => '2027-07-05', 'nombre' => 'San Pedro y San Pablo'],
            ['fecha' => '2027-07-20', 'nombre' => 'Día de la Independencia'],
            ['fecha' => '2027-08-07', 'nombre' => 'Batalla de Boyacá'],
            ['fecha' => '2027-08-16', 'nombre' => 'La Asunción de la Virgen'],
            ['fecha' => '2027-10-18', 'nombre' => 'Día de la Raza'],
            ['fecha' => '2027-11-01', 'nombre' => 'Día de Todos los Santos'],
            ['fecha' => '2027-11-15', 'nombre' => 'Independencia de Cartagena'],
            ['fecha' => '2027-12-08', 'nombre' => 'Día de la Inmaculada Concepción'],
            ['fecha' => '2027-12-25', 'nombre' => 'Día de Navidad'],
        ];
    }
}
