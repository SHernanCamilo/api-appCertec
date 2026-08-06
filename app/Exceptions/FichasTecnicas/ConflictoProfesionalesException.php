<?php

declare(strict_types=1);

namespace App\Exceptions\FichasTecnicas;

use App\DTO\FichasTecnicas\ConflictoProfesionalDTO;
use RuntimeException;

/**
 * Un profesional ya está vinculado a una ficha vigente con fechas solapadas.
 *
 * En el legacy este caso devolvía un `echo json_encode(...)` desde
 * `insertar.php` y en otras rutas un `header('location: ...?insertar=error')`.
 */
final class ConflictoProfesionalesException extends RuntimeException
{
    /**
     * @param  list<ConflictoProfesionalDTO>  $conflictos
     */
    public function __construct(private readonly array $conflictos)
    {
        parent::__construct($this->construirMensaje());
    }

    /**
     * @return list<ConflictoProfesionalDTO>
     */
    public function conflictos(): array
    {
        return $this->conflictos;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conflictosArray(): array
    {
        return array_map(
            static fn (ConflictoProfesionalDTO $c): array => $c->toArray(),
            $this->conflictos
        );
    }

    /**
     * Solo los conflictos de tipo BLOQUEO llegan a esta excepción (RN-02).
     * Las alertas informativas (RN-01) se devuelven al llamador sin interrumpir.
     */
    private function construirMensaje(): string
    {
        /** @var array<string, list<string>> $porProfesional */
        $porProfesional = [];

        foreach ($this->conflictos as $conflicto) {
            $agremiacion = $conflicto->agremiacionNombre ?? 'otra agremiación';

            $porProfesional[$conflicto->nombreProfesional][] = sprintf(
                'ficha %s (%s)',
                $conflicto->consecutivo,
                $agremiacion
            );
        }

        $lineas = [];
        foreach ($porProfesional as $nombre => $referencias) {
            $lineas[] = $nombre.' → '.implode(', ', array_unique($referencias));
        }

        return 'No es posible continuar. Los siguientes profesionales ya tienen fichas '
            .'vigentes con una agremiación diferente en el rango de fechas indicado: '
            .implode(' | ', $lineas);
    }
}
