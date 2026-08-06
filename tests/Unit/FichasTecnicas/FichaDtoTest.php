<?php

declare(strict_types=1);

namespace Tests\Unit\FichasTecnicas;

use App\DTO\FichasTecnicas\CrearFichaDTO;
use App\DTO\FichasTecnicas\DetalleFichaDTO;
use PHPUnit\Framework\TestCase;

/**
 * Normalización de los datos que llegaban del sistema JADE legacy, donde los
 * valores monetarios se guardaban como varchar con formato de presentación
 * ("$1,234,567.89" o "$1.234.567,89") y los campos vacíos como '0' o 'NULL'.
 */
final class FichaDtoTest extends TestCase
{
    /**
     * @dataProvider proveedorDeMoneda
     */
    public function testNormalizacionDeMoneda(mixed $entrada, float $esperado): void
    {
        $dto = DetalleFichaDTO::fromArray(['valor' => $entrada]);

        $this->assertSame($esperado, $dto->valor, 'Entrada: '.var_export($entrada, true));
    }

    /**
     * @return array<string, array{mixed, float}>
     */
    public static function proveedorDeMoneda(): array
    {
        return [
            'numero entero'          => [1250000, 1250000.0],
            'numero decimal'         => [1250000.55, 1250000.55],
            'cadena numerica'        => ['1250000', 1250000.0],
            'formato US con simbolo' => ['$1,250,000.00', 1250000.0],
            'formato US sin simbolo' => ['1,250,000.00', 1250000.0],
            'formato CO con simbolo' => ['$980.500,50', 980500.50],
            'formato CO sin decimal' => ['980.500', 980500.0],
            'cadena vacia'           => ['', 0.0],
            'literal NULL legacy'    => ['NULL', 0.0],
            'con espacios'           => ['  $ 45.000,00  ', 45000.0],
        ];
    }

    public function testCrearFichaDtoNormalizaMonedaYFechas(): void
    {
        $dto = CrearFichaDTO::fromArray([
            'id_agremiacion'     => '5',
            'id_objeto_contrato' => '2',
            'id_especialidad'    => '10',
            'vlr_contrato'       => '$1.500.000,75',
            'fecha_ini'          => '2026-03-01',
            'fecha_fin'          => '2027-02-28',
            'profesionales'      => ['3', '7', '3'],  // con duplicado
            'id_user_reg'        => '1',
        ]);

        $this->assertSame(5, $dto->idAgremiacion);
        $this->assertSame(1500000.75, $dto->vlrContrato);
        $this->assertSame('2026-03-01', $dto->fechaIni->toDateString());
        $this->assertSame('2027-02-28', $dto->fechaFin->toDateString());
        $this->assertSame([3, 7], $dto->profesionales, 'Debe eliminar profesionales duplicados');
    }

    public function testDetectaSiEsActualizacion(): void
    {
        $base = [
            'id_agremiacion'     => 1,
            'id_objeto_contrato' => 1,
            'id_especialidad'    => 1,
            'vlr_contrato'       => 1000,
            'fecha_ini'          => '2026-01-01',
            'fecha_fin'          => '2026-12-31',
            'profesionales'      => [1],
            'id_user_reg'        => 1,
        ];

        $this->assertFalse(CrearFichaDTO::fromArray($base)->esActualizacion());
        $this->assertTrue(CrearFichaDTO::fromArray($base + ['id_padre' => 42])->esActualizacion());
    }

    /**
     * El legacy enviaba '0' cuando no se seleccionaba observación
     * (`insertar2.php`), lo que creaba una FK inválida.
     */
    public function testObsItemCeroSeConvierteEnNull(): void
    {
        $this->assertNull(DetalleFichaDTO::fromArray(['id_obs_item' => '0', 'valor' => 0])->idObsItem);
        $this->assertNull(DetalleFichaDTO::fromArray(['id_obs_item' => 0, 'valor' => 0])->idObsItem);
        $this->assertNull(DetalleFichaDTO::fromArray(['id_obs_item' => '', 'valor' => 0])->idObsItem);
        $this->assertSame(4, DetalleFichaDTO::fromArray(['id_obs_item' => '4', 'valor' => 0])->idObsItem);
    }

    public function testCadenasVaciasSeConviertenEnNull(): void
    {
        $dto = DetalleFichaDTO::fromArray([
            'cups'       => '',
            'grupo'      => '   ',
            'forma_pago' => 'PRODUCCION',
            'valor'      => 100,
        ]);

        $this->assertNull($dto->cups);
        $this->assertNull($dto->grupo);
        $this->assertSame('PRODUCCION', $dto->formaPago);
    }

    public function testCollectionMapeaVariosItems(): void
    {
        $dtos = DetalleFichaDTO::collection([
            ['cups' => '470101', 'valor' => '1.000,00'],
            ['cups' => '890201', 'valor' => 2000],
        ]);

        $this->assertCount(2, $dtos);
        $this->assertSame(1000.0, $dtos[0]->valor);
        $this->assertSame(2000.0, $dtos[1]->valor);
    }

    public function testToModelAttributesDevuelveLasClavesDeLaTabla(): void
    {
        $atributos = DetalleFichaDTO::fromArray(['cups' => '470101', 'valor' => 500])->toModelAttributes();

        $this->assertSame([
            'tipo_liquidacion', 'tipo_servicio', 'id_tipo_servicio', 'cups',
            'grupo', 'subgrupo', 'forma_pago', 'homologo', 'variacion',
            'valor', 'id_obs_item', 'novedad',
        ], array_keys($atributos));
    }
}
