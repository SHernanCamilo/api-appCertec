<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Exceptions\FichasTecnicas\VentanaEnvioCerradaException;
use Illuminate\Support\Carbon;

/**
 * RN-03 — Ventana de envío de fichas a autorización.
 *
 * El envío se cierra después del día límite del mes (21 por defecto) y se
 * reabre el día 01 del mes siguiente. Guardar y editar el borrador sigue
 * permitido: la restricción aplica solo a la transición al flujo de aprobación.
 *
 * Se expone también como consulta (`estado()`) para que el frontend deshabilite
 * el botón con el mismo criterio que valida el backend, en lugar de duplicar la
 * regla en TypeScript.
 */
final class FichVentanaEnvioService
{
    public function diaLimite(): ?int
    {
        $limite = config('fichas_tecnicas.dia_limite_envio');

        if ($limite === null || $limite === '' || $limite === false) {
            return null;
        }

        $limite = (int) $limite;

        return $limite >= 1 && $limite <= 31 ? $limite : null;
    }

    public function ahora(): Carbon
    {
        return Carbon::now(config('fichas_tecnicas.zona_horaria', 'America/Bogota'));
    }

    /** Indica si en este momento se puede enviar una ficha a autorización. */
    public function estaAbierta(?Carbon $momento = null): bool
    {
        $limite = $this->diaLimite();

        if ($limite === null) {
            return true;
        }

        return ($momento ?? $this->ahora())->day <= $limite;
    }

    /** Primer día del mes siguiente: momento en que la ventana se reabre. */
    public function proximaApertura(?Carbon $momento = null): Carbon
    {
        return ($momento ?? $this->ahora())->copy()->addMonthNoOverflow()->startOfMonth();
    }

    /** Último instante en que se puede enviar dentro del mes en curso. */
    public function cierreActual(?Carbon $momento = null): ?Carbon
    {
        $limite = $this->diaLimite();

        if ($limite === null) {
            return null;
        }

        $referencia = $momento ?? $this->ahora();
        $diaCierre  = min($limite, $referencia->daysInMonth);

        return $referencia->copy()->setDay($diaCierre)->endOfDay();
    }

    /**
     * @throws VentanaEnvioCerradaException
     */
    public function garantizarAbierta(?Carbon $momento = null): void
    {
        if ($this->estaAbierta($momento)) {
            return;
        }

        throw new VentanaEnvioCerradaException(
            (int) $this->diaLimite(),
            $this->proximaApertura($momento)
        );
    }

    /**
     * Estado de la ventana para el frontend.
     *
     * @return array<string, mixed>
     */
    public function estado(?Carbon $momento = null): array
    {
        $referencia = $momento ?? $this->ahora();
        $limite     = $this->diaLimite();
        $abierta    = $this->estaAbierta($referencia);

        return [
            'abierta'          => $abierta,
            'dia_actual'       => $referencia->day,
            'dia_limite'       => $limite,
            'cierra_el'        => $this->cierreActual($referencia)?->toDateString(),
            'reabre_el'        => $abierta ? null : $this->proximaApertura($referencia)->toDateString(),
            'dias_restantes'   => $abierta && $limite !== null ? max(0, $limite - $referencia->day) : 0,
            'mensaje'          => $abierta
                ? null
                : sprintf(
                    'No es posible enviar fichas para autorización después del día %d del mes. '
                    .'Podrá realizar el envío nuevamente a partir del %s.',
                    $limite,
                    $this->proximaApertura($referencia)->format('d/m/Y')
                ),
        ];
    }
}
