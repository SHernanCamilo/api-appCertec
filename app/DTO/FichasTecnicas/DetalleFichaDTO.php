<?php

declare(strict_types=1);

namespace App\DTO\FichasTecnicas;

/**
 * Ítem de servicio del paso 2 del generador.
 *
 * Reemplaza los 12 parámetros posicionales sin tipo de
 * `config/config2.php::insertar()`.
 */
final readonly class DetalleFichaDTO
{
    public function __construct(
        public ?string $tipoLiquidacion = null,
        public ?string $tipoServicio = null,
        public ?int $idTipoServicio = null,
        public ?string $cups = null,
        public ?string $grupo = null,
        public ?string $subgrupo = null,
        public ?string $formaPago = null,
        public ?string $homologo = null,
        public ?string $variacion = null,
        public float $valor = 0.0,
        public ?int $idObsItem = null,
        public ?string $novedad = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tipoLiquidacion: self::texto($data['tipo_liquidacion'] ?? null),
            tipoServicio:    self::texto($data['tipo_servicio'] ?? null),
            idTipoServicio:  isset($data['id_tipo_servicio']) && $data['id_tipo_servicio'] !== ''
                                ? (int) $data['id_tipo_servicio'] : null,
            cups:            self::texto($data['cups'] ?? null),
            grupo:           self::texto($data['grupo'] ?? null),
            subgrupo:        self::texto($data['subgrupo'] ?? null),
            formaPago:       self::texto($data['forma_pago'] ?? null),
            homologo:        self::texto($data['homologo'] ?? null),
            variacion:       self::texto($data['variacion'] ?? null),
            valor:           self::normalizarMoneda($data['valor'] ?? 0),
            // El legacy enviaba '0' cuando no había observación: se normaliza a null.
            idObsItem:       ! empty($data['id_obs_item']) && (int) $data['id_obs_item'] > 0
                                ? (int) $data['id_obs_item'] : null,
            novedad:         self::texto($data['novedad'] ?? null),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<self>
     */
    public static function collection(array $items): array
    {
        return array_map(static fn (array $i): self => self::fromArray($i), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'tipo_liquidacion' => $this->tipoLiquidacion,
            'tipo_servicio'    => $this->tipoServicio,
            'id_tipo_servicio' => $this->idTipoServicio,
            'cups'             => $this->cups,
            'grupo'            => $this->grupo,
            'subgrupo'         => $this->subgrupo,
            'forma_pago'       => $this->formaPago,
            'homologo'         => $this->homologo,
            'variacion'        => $this->variacion,
            'valor'            => $this->valor,
            'id_obs_item'      => $this->idObsItem,
            'novedad'          => $this->novedad,
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private static function normalizarMoneda(mixed $valor): float
    {
        return MoneyParser::aFloat($valor);
    }
}
