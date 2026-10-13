<?php

namespace Tests\Unit\Services\MesaServicio;

use App\Services\GLPI\GLPIService;
use App\Services\MesaServicio\GlpiTicketsTicService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class GlpiTicketsTicServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_elige_la_asignacion_mas_reciente_y_descarta_solicitantes(): void
    {
        $glpi = Mockery::mock(GLPIService::class);
        $glpi->shouldReceive('get')
            ->once()
            ->andReturn([
                'totalcount' => 4,
                'data' => [
                    ['2' => 10, '3' => 492357, '4' => 7, '5' => 2],
                    ['2' => 25, '3' => 492357, '4' => 9, '5' => 2],
                    ['2' => 18, '3' => '492430 - Fallo en índigo', '4' => 7, '5' => 'Asignado'],
                    ['2' => 30, '3' => 492357, '4' => 3, '5' => 1],
                ],
            ]);

        $resultado = $this->invocar(new GlpiTicketsTicService($glpi), 'consultarUltimoTecnico', [[492357, 492430]]);

        $this->assertSame([
            492357 => 9,
            492430 => 7,
        ], $resultado);
    }

    public function test_resuelve_el_usuario_cuando_la_busqueda_no_trae_el_id(): void
    {
        $glpi = Mockery::mock(GLPIService::class);
        $glpi->shouldReceive('get')
            ->once()
            ->with('/search/Ticket_User', Mockery::type('array'))
            ->andReturn([
                'totalcount' => 2,
                'data' => [
                    ['2' => 10, '3' => 100, '4' => 'edwin', '5' => 2],
                    ['2' => 40, '3' => 100, '4' => 'camila', '5' => 2],
                ],
            ]);
        $glpi->shouldReceive('get')
            ->once()
            ->with('/getMultipleItems', Mockery::type('array'))
            ->andReturn([
                ['id' => 40, 'users_id' => 9, 'type' => 2],
            ]);
        $glpi->shouldReceive('normalizeCollection')
            ->once()
            ->andReturnUsing(fn (mixed $batch): array => is_array($batch) ? array_values($batch) : []);

        $resultado = $this->invocar(new GlpiTicketsTicService($glpi), 'consultarUltimoTecnico', [[100]]);

        $this->assertSame([100 => 9], $resultado);
    }

    public function test_la_columna_muestra_solo_el_ultimo_tecnico(): void
    {
        $service = new GlpiTicketsTicService(Mockery::mock(GLPIService::class));

        $porHistorico = $this->invocar($service, 'tecnicoVisible', [
            'Edwin Vargas Gonzalez, Camila Alexandra Montes Muñoz',
            [9 => 'Camila Alexandra Montes Muñoz', 7 => 'Edwin Vargas Gonzalez'],
            9,
        ]);
        $porLista = $this->invocar($service, 'tecnicoVisible', [
            'Edwin Vargas Gonzalez, Camila Alexandra Montes Muñoz',
            [],
            null,
        ]);
        $sinAsignar = $this->invocar($service, 'tecnicoVisible', [null, [], null]);

        $this->assertSame('Camila Alexandra Montes Muñoz', $porHistorico);
        $this->assertSame('Camila Alexandra Montes Muñoz', $porLista);
        $this->assertSame('Sin asignar', $sinAsignar);
    }

    /**
     * @param  list<mixed>  $argumentos
     */
    private function invocar(GlpiTicketsTicService $service, string $metodo, array $argumentos): mixed
    {
        $reflexion = new ReflectionMethod($service, $metodo);

        return $reflexion->invokeArgs($service, $argumentos);
    }
}
