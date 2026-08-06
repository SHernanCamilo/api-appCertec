<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\DTO\FichasTecnicas\ConflictoProfesionalDTO;
use App\Exceptions\FichasTecnicas\ConflictoProfesionalesException;
use Illuminate\Support\Facades\DB;

/**
 * Validación de conflictos de profesionales entre fichas con vigencia.
 *
 * Reglas de negocio (rediseño 2026-08):
 *
 *  RN-01 — ALERTA: el profesional ya tiene ficha vigente en la MISMA
 *          agremiación. Se informa pero no se bloquea; es un escenario legítimo
 *          (renovación, ampliación de servicios, segunda especialidad).
 *
 *  RN-02 — BLOQUEO: el profesional tiene ficha vigente en OTRA agremiación.
 *          No puede estar contratado simultáneamente por dos prestadores.
 *
 * El sistema JADE legacy (`generador/acciones/insertar.php`) trataba cualquier
 * solapamiento como error y no consultaba la agremiación, por lo que impedía
 * recontratar al mismo profesional dentro del mismo prestador. Además construía
 * el SQL concatenando placeholders y usaba tres cláusulas OR redundantes para el
 * solapamiento; aquí se delega en `sp_fich_conflictos_profesionales`, que aplica
 * la comparación canónica de intervalos `ini <= fin_nuevo AND fin >= ini_nuevo`.
 */
final class FichConflictoService
{
    /**
     * Detecta todos los conflictos (alertas y bloqueos) sin lanzar excepción.
     *
     * @param  list<int>  $idsProfesionales
     * @param  int|null   $idAgremiacion  Agremiación de la ficha en curso. Si es
     *                                    null, todo se clasifica como ALERTA.
     * @return list<ConflictoProfesionalDTO>
     */
    public function detectar(
        array $idsProfesionales,
        string $fechaIni,
        string $fechaFin,
        ?int $excluirFichaId = null,
        ?int $idAgremiacion = null,
    ): array {
        $ids = array_values(array_filter(
            array_map('intval', $idsProfesionales),
            static fn (int $i): bool => $i > 0
        ));

        if ($ids === []) {
            return [];
        }

        $filas = DB::select(
            'CALL sp_fich_conflictos_profesionales(?, ?, ?, ?, ?)',
            [implode(',', $ids), $fechaIni, $fechaFin, $excluirFichaId, $idAgremiacion]
        );

        return array_map(
            static fn (object $row): ConflictoProfesionalDTO => ConflictoProfesionalDTO::fromRow($row),
            $filas
        );
    }

    /**
     * Solo los conflictos que impiden continuar (RN-02).
     *
     * @param  list<ConflictoProfesionalDTO>  $conflictos
     * @return list<ConflictoProfesionalDTO>
     */
    public function soloBloqueos(array $conflictos): array
    {
        return array_values(array_filter(
            $conflictos,
            static fn (ConflictoProfesionalDTO $c): bool => $c->esBloqueo()
        ));
    }

    /**
     * Solo los conflictos informativos (RN-01).
     *
     * @param  list<ConflictoProfesionalDTO>  $conflictos
     * @return list<ConflictoProfesionalDTO>
     */
    public function soloAlertas(array $conflictos): array
    {
        return array_values(array_filter(
            $conflictos,
            static fn (ConflictoProfesionalDTO $c): bool => $c->esAlerta()
        ));
    }

    /**
     * Valida y lanza excepción únicamente si hay conflictos de tipo BLOQUEO.
     *
     * Las alertas (RN-01) no interrumpen: se devuelven para que la capa de
     * presentación las muestre.
     *
     * @param  list<int>  $idsProfesionales
     * @return list<ConflictoProfesionalDTO>  Alertas informativas detectadas
     *
     * @throws ConflictoProfesionalesException  Si existe al menos un bloqueo
     */
    public function validar(
        array $idsProfesionales,
        string $fechaIni,
        string $fechaFin,
        ?int $excluirFichaId = null,
        ?int $idAgremiacion = null,
    ): array {
        $conflictos = $this->detectar(
            $idsProfesionales,
            $fechaIni,
            $fechaFin,
            $excluirFichaId,
            $idAgremiacion
        );

        $bloqueos = $this->soloBloqueos($conflictos);

        if ($bloqueos !== []) {
            throw new ConflictoProfesionalesException($bloqueos);
        }

        return $this->soloAlertas($conflictos);
    }

    /**
     * Resumen para la respuesta del endpoint de verificación previa.
     *
     * @param  list<int>  $idsProfesionales
     * @return array<string, mixed>
     */
    public function resumen(
        array $idsProfesionales,
        string $fechaIni,
        string $fechaFin,
        ?int $excluirFichaId = null,
        ?int $idAgremiacion = null,
    ): array {
        $conflictos = $this->detectar(
            $idsProfesionales,
            $fechaIni,
            $fechaFin,
            $excluirFichaId,
            $idAgremiacion
        );

        $bloqueos = $this->soloBloqueos($conflictos);
        $alertas  = $this->soloAlertas($conflictos);

        return [
            'puede_continuar' => $bloqueos === [],
            'total'           => count($conflictos),
            'bloqueos'        => array_map(static fn ($c) => $c->toArray(), $bloqueos),
            'alertas'         => array_map(static fn ($c) => $c->toArray(), $alertas),
        ];
    }
}
