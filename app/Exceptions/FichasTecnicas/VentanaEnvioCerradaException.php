<?php

declare(strict_types=1);

namespace App\Exceptions\FichasTecnicas;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * RN-03 — El envío a autorización está cerrado por la fecha del calendario.
 *
 * La ventana se cierra después del día límite del mes (por defecto el 21) y se
 * reabre el día 01 del mes siguiente. El borrador se puede seguir editando y
 * guardando; solo se bloquea la entrada al flujo de aprobación.
 */
final class VentanaEnvioCerradaException extends RuntimeException
{
    public function __construct(
        public readonly int $diaLimite,
        public readonly Carbon $reaperturaEn,
    ) {
        parent::__construct(sprintf(
            'No es posible enviar fichas para autorización después del día %d del mes. '
            .'Podrá realizar el envío nuevamente a partir del %s. '
            .'Mientras tanto puede seguir editando y guardando el borrador.',
            $diaLimite,
            $reaperturaEn->format('d/m/Y')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function contexto(): array
    {
        return [
            'regla'         => 'RN-03',
            'dia_limite'    => $this->diaLimite,
            'reapertura_en' => $this->reaperturaEn->toDateString(),
        ];
    }
}
