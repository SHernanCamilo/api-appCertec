<?php

declare(strict_types=1);

namespace App\Exceptions\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use DomainException;

/**
 * Intento de mover una ficha a un estado no permitido por el flujo.
 *
 * Hace cumplir en código la regla R2 del legacy ("flujo secuencial: nunca
 * saltar de 1 a 5"), que antes solo estaba escrita en la documentación.
 */
final class TransicionEstadoInvalidaException extends DomainException
{
    public function __construct(
        public readonly EstadoFicha $origen,
        public readonly EstadoFicha $destino,
    ) {
        $permitidos = array_map(
            static fn (EstadoFicha $e): string => $e->label(),
            $origen->transicionesPermitidas()
        );

        parent::__construct(sprintf(
            'Transición no permitida: "%s" → "%s". Estados válidos desde "%s": %s.',
            $origen->label(),
            $destino->label(),
            $origen->label(),
            $permitidos === [] ? 'ninguno (estado terminal)' : implode(', ', $permitidos)
        ));
    }
}
