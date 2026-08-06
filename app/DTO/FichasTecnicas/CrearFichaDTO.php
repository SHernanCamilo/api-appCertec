<?php

declare(strict_types=1);

namespace App\DTO\FichasTecnicas;

use Illuminate\Support\Carbon;

/**
 * Datos del paso 1 del generador: cabecera de la ficha técnica.
 *
 * Reemplaza el arreglo `$_POST` sin tipar de
 * `generador/acciones/insertar.php`.
 */
final readonly class CrearFichaDTO
{
    /**
     * @param  list<int>  $profesionales
     */
    public function __construct(
        public int $idAgremiacion,
        public int $idObjetoContrato,
        public int $idEspecialidad,
        public float $vlrContrato,
        public Carbon $fechaIni,
        public Carbon $fechaFin,
        public array $profesionales,
        public int $idUserReg,
        public ?int $idEmpresa = null,
        public ?int $idSucursal = null,
        public ?string $sucursalLegacy = null,
        public ?int $idPadre = null,
        public ?string $obsOs = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            idAgremiacion:    (int) $data['id_agremiacion'],
            idObjetoContrato: (int) $data['id_objeto_contrato'],
            idEspecialidad:   (int) $data['id_especialidad'],
            vlrContrato:      self::normalizarMoneda($data['vlr_contrato'] ?? 0),
            fechaIni:         Carbon::parse((string) $data['fecha_ini'])->startOfDay(),
            fechaFin:         Carbon::parse((string) $data['fecha_fin'])->startOfDay(),
            profesionales:    array_values(array_unique(array_map('intval', (array) ($data['profesionales'] ?? [])))),
            idUserReg:        (int) $data['id_user_reg'],
            idEmpresa:        isset($data['id_empresa']) ? (int) $data['id_empresa'] : null,
            idSucursal:       isset($data['id_sucursal']) ? (int) $data['id_sucursal'] : null,
            sucursalLegacy:   isset($data['sucursal_legacy']) ? (string) $data['sucursal_legacy'] : null,
            idPadre:          isset($data['id_padre']) ? (int) $data['id_padre'] : null,
            obsOs:            isset($data['obs_os']) ? (string) $data['obs_os'] : null,
        );
    }

    /**
     * Normaliza valores monetarios que llegan con formato del legacy
     * ("$1,234,567.89" o "$1.234.567,89") o como número plano.
     */
    private static function normalizarMoneda(mixed $valor): float
    {
        return MoneyParser::aFloat($valor);
    }

    public function esActualizacion(): bool
    {
        return $this->idPadre !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'id_agremiacion'     => $this->idAgremiacion,
            'id_objeto_contrato' => $this->idObjetoContrato,
            'id_especialidad'    => $this->idEspecialidad,
            'vlr_contrato'       => $this->vlrContrato,
            'fecha_ini'          => $this->fechaIni->toDateString(),
            'fecha_fin'          => $this->fechaFin->toDateString(),
            'id_user_reg'        => $this->idUserReg,
            'id_empresa'         => $this->idEmpresa,
            'id_sucursal'        => $this->idSucursal,
            'sucursal_legacy'    => $this->sucursalLegacy,
            'id_padre'           => $this->idPadre,
            'obs_os'             => $this->obsOs,
        ];
    }
}
