<?php

namespace App\Services\TalentoHumano;

use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfPaso;

/**
 * Traduce el estado de una instancia de flujo al estado numérico
 * de event_horas_extra (1..6).
 *
 *   1 Registrado    | en progreso, pendiente de aprobar
 *   2 Aprobado      | en progreso, pendiente de autorizar
 *   3 Autorizado    | en progreso, pendiente de digitalizar
 *   4 Rechazado     | instancia rechazada
 *   5 Digitalizado  | instancia completada
 *   6 Anulado       | instancia cancelada
 */
class EventoEstadoMapper
{
    public const REGISTRADO   = 1;
    public const APROBADO     = 2;
    public const AUTORIZADO   = 3;
    public const RECHAZADO    = 4;
    public const DIGITALIZADO = 5;
    public const ANULADO      = 6;

    public static function desdeInstancia(WfInstancia $instancia): int
    {
        if ($instancia->estaCompletado()) {
            return self::DIGITALIZADO;
        }

        if ($instancia->estaRechazado()) {
            return self::RECHAZADO;
        }

        if ($instancia->estado === WfInstancia::ESTADO_CANCELADO) {
            return self::ANULADO;
        }

        return self::desdeRol(optional($instancia->pasoActual)->rol_aprobador);
    }

    /**
     * Mapea el rol del paso actual (lo que falta por hacer) al estado
     * de negocio (lo que ya se completó).
     */
    public static function desdeRol(?string $rolAprobador): int
    {
        return match ($rolAprobador) {
            'aprobador'     => self::REGISTRADO,   // pendiente de aprobar
            'autorizador'   => self::APROBADO,     // ya se aprobó
            'digitalizador' => self::AUTORIZADO,   // ya se autorizó
            default         => self::REGISTRADO,
        };
    }

    public static function label(int $estado): string
    {
        return [
            self::REGISTRADO   => 'Registrado',
            self::APROBADO     => 'Aprobado',
            self::AUTORIZADO   => 'Autorizado',
            self::RECHAZADO    => 'Rechazado',
            self::DIGITALIZADO => 'Digitalizado',
            self::ANULADO      => 'Anulado',
        ][$estado] ?? 'Desconocido';
    }
}
