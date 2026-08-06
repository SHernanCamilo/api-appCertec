<?php

declare(strict_types=1);

namespace App\DTO\FichasTecnicas;

/**
 * Conflicto detectado al validar que un profesional no esté comprometido en
 * otra ficha con vigencia solapada.
 *
 * Se distinguen dos severidades (rediseño 2026-08):
 *
 *  - ALERTA  (RN-01): el profesional ya tiene ficha vigente con la MISMA
 *            agremiación. Se informa a generador, director médico y VP
 *            financiero, pero NO se bloquea la operación.
 *  - BLOQUEO (RN-02): el profesional tiene ficha vigente con OTRA agremiación.
 *            Se impide crear o enviar la ficha.
 *
 * El sistema JADE legacy trataba todo solapamiento como error, sin mirar la
 * agremiación, lo que impedía contratar de nuevo al mismo profesional dentro
 * de la misma agremiación.
 */
final readonly class ConflictoProfesionalDTO
{
    public const TIPO_ALERTA  = 'ALERTA';
    public const TIPO_BLOQUEO = 'BLOQUEO';

    public function __construct(
        public int $idProfesional,
        public string $nombreProfesional,
        public string $documento,
        public int $idFicha,
        public string $consecutivo,
        public string $fechaIni,
        public string $fechaFin,
        public ?string $sucursal,
        public string $tipoConflicto = self::TIPO_ALERTA,
        public ?int $idAgremiacion = null,
        public ?string $agremiacionNombre = null,
        public ?string $especialidad = null,
        public ?string $estadoCodigo = null,
        public ?string $estadoDescripcion = null,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $tipo = strtoupper((string) ($row->tipo_conflicto ?? self::TIPO_ALERTA));

        return new self(
            idProfesional:     (int) $row->id_profesional,
            nombreProfesional: (string) $row->profesional_nombre,
            documento:         (string) ($row->profesional_documento ?? ''),
            idFicha:           (int) $row->id_ficha,
            consecutivo:       (string) ($row->consecutivo ?? 'SIN-CONSECUTIVO'),
            fechaIni:          (string) $row->fecha_ini,
            fechaFin:          (string) $row->fecha_fin,
            sucursal:          isset($row->sucursal_legacy) ? (string) $row->sucursal_legacy : null,
            tipoConflicto:     $tipo === self::TIPO_BLOQUEO ? self::TIPO_BLOQUEO : self::TIPO_ALERTA,
            idAgremiacion:     isset($row->id_agremiacion) ? (int) $row->id_agremiacion : null,
            agremiacionNombre: isset($row->agremiacion_nombre) ? (string) $row->agremiacion_nombre : null,
            especialidad:      isset($row->especialidad_descripcion) ? (string) $row->especialidad_descripcion : null,
            estadoCodigo:      isset($row->estado_codigo) ? (string) $row->estado_codigo : null,
            estadoDescripcion: isset($row->estado_descripcion) ? (string) $row->estado_descripcion : null,
        );
    }

    public function esBloqueo(): bool
    {
        return $this->tipoConflicto === self::TIPO_BLOQUEO;
    }

    public function esAlerta(): bool
    {
        return $this->tipoConflicto === self::TIPO_ALERTA;
    }

    /** Mensaje listo para mostrar al usuario, según la severidad. */
    public function mensaje(): string
    {
        if ($this->esBloqueo()) {
            return sprintf(
                'No es posible continuar. El profesional %s ya posee una ficha vigente (%s) '
                .'asociada a una agremiación diferente: %s. Vigencia %s al %s.',
                $this->nombreProfesional,
                $this->consecutivo,
                $this->agremiacionNombre ?? 'sin identificar',
                $this->fechaIni,
                $this->fechaFin
            );
        }

        return sprintf(
            'El profesional %s ya posee una ficha vigente (%s) en la misma agremiación. '
            .'Estado: %s. Vigencia %s al %s. Verifique antes de continuar.',
            $this->nombreProfesional,
            $this->consecutivo,
            $this->estadoDescripcion ?? 'vigente',
            $this->fechaIni,
            $this->fechaFin
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id_profesional'      => $this->idProfesional,
            'nombre_profesional'  => $this->nombreProfesional,
            'documento'           => $this->documento,
            'id_ficha'            => $this->idFicha,
            'consecutivo'         => $this->consecutivo,
            'fecha_ini'           => $this->fechaIni,
            'fecha_fin'           => $this->fechaFin,
            'sucursal'            => $this->sucursal,
            'tipo_conflicto'      => $this->tipoConflicto,
            'id_agremiacion'      => $this->idAgremiacion,
            'agremiacion_nombre'  => $this->agremiacionNombre,
            'especialidad'        => $this->especialidad,
            'estado_codigo'       => $this->estadoCodigo,
            'estado_descripcion'  => $this->estadoDescripcion,
            'mensaje'             => $this->mensaje(),
        ];
    }
}
