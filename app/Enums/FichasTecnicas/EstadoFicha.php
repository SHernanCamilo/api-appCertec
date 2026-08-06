<?php

declare(strict_types=1);

namespace App\Enums\FichasTecnicas;

/**
 * Estados del workflow de Fichas Técnicas.
 *
 * Rediseño 2026-08: se reemplazan los 12 estados heredados del sistema JADE
 * legacy (donde "GENERADA", "EN PROCESO" y "POR APROBAR" se solapaban) por un
 * flujo explícito de 7 estados, espejado para el flujo de actualizaciones (OS).
 *
 * Flujo de una ficha:
 *
 *   borrador ──enviar──► pendiente_autorizacion ──autorizar──► pendiente_revision_financiera
 *      ▲                        │                                      │
 *      │                     rechazar                              aprobar │ rechazar
 *      │                        ▼                                      ▼    ▼
 *      └── (editar) ── correccion_requerida ◄──────────────────────────┘  aprobada
 *                                                                             │
 *                                                          (fecha_ini llegó)   ▼
 *                                                                          vigente
 *                                                                             │
 *                                                       solicitar modificación ▼
 *                                                                        [nueva OS]
 *
 * Una ficha vigente NO se edita en sitio: el generador solicita una
 * modificación y el sistema crea una nueva versión (OS) que recorre el flujo
 * completo de nuevo. Así la vigencia y la trazabilidad de la versión anterior
 * quedan intactas.
 *
 * El valor del enum es el `codigo` de `fich_estados`; `id()` devuelve el
 * entero que también es PK de la tabla.
 */
enum EstadoFicha: string
{
    // ── Flujo de ficha original ──────────────────────────────────────────
    case Borrador                    = 'borrador';                      // 1
    case PendienteAutorizacion       = 'pendiente_autorizacion';        // 2
    case CorreccionRequerida         = 'correccion_requerida';          // 3
    case PendienteRevisionFinanciera = 'pendiente_revision_financiera'; // 4
    case Aprobada                    = 'aprobada';                      // 5
    case Vigente                     = 'vigente';                       // 6
    case Cancelada                   = 'cancelada';                     // 7

    // ── Flujo de actualización (OS) ──────────────────────────────────────
    case OsBorrador                    = 'os_borrador';                      // 8
    case OsPendienteAutorizacion       = 'os_pendiente_autorizacion';        // 9
    case OsCorreccionRequerida         = 'os_correccion_requerida';          // 10
    case OsPendienteRevisionFinanciera = 'os_pendiente_revision_financiera'; // 11
    case OsAprobada                    = 'os_aprobada';                      // 12
    case OsVigente                     = 'os_vigente';                       // 13
    case OsCancelada                   = 'os_cancelada';                     // 14

    /** ID numérico en `fich_estados`. */
    public function id(): int
    {
        return match ($this) {
            self::Borrador                    => 1,
            self::PendienteAutorizacion       => 2,
            self::CorreccionRequerida         => 3,
            self::PendienteRevisionFinanciera => 4,
            self::Aprobada                    => 5,
            self::Vigente                     => 6,
            self::Cancelada                   => 7,

            self::OsBorrador                    => 8,
            self::OsPendienteAutorizacion       => 9,
            self::OsCorreccionRequerida         => 10,
            self::OsPendienteRevisionFinanciera => 11,
            self::OsAprobada                    => 12,
            self::OsVigente                     => 13,
            self::OsCancelada                   => 14,
        };
    }

    public static function fromId(int $id): self
    {
        foreach (self::cases() as $case) {
            if ($case->id() === $id) {
                return $case;
            }
        }

        throw new \ValueError("Estado de ficha desconocido: {$id}");
    }

    public static function tryFromId(int $id): ?self
    {
        try {
            return self::fromId($id);
        } catch (\ValueError) {
            return null;
        }
    }

    public function label(): string
    {
        return match ($this) {
            self::Borrador                    => 'Borrador',
            self::PendienteAutorizacion       => 'Pendiente de autorización',
            self::CorreccionRequerida         => 'Corrección requerida',
            self::PendienteRevisionFinanciera => 'Pendiente de revisión financiera',
            self::Aprobada                    => 'Aprobada',
            self::Vigente                     => 'Vigente',
            self::Cancelada                   => 'Cancelada',

            self::OsBorrador                    => 'Actualización — Borrador',
            self::OsPendienteAutorizacion       => 'Actualización — Pendiente de autorización',
            self::OsCorreccionRequerida         => 'Actualización — Corrección requerida',
            self::OsPendienteRevisionFinanciera => 'Actualización — Pendiente de revisión financiera',
            self::OsAprobada                    => 'Actualización — Aprobada',
            self::OsVigente                     => 'Actualización — Vigente',
            self::OsCancelada                   => 'Actualización — Cancelada',
        };
    }

    public function colorHex(): string
    {
        return match ($this) {
            self::Borrador, self::OsBorrador                                       => '#6c757d',
            self::PendienteAutorizacion, self::OsPendienteAutorizacion             => '#0dcaf0',
            self::CorreccionRequerida, self::OsCorreccionRequerida                 => '#dc3545',
            self::PendienteRevisionFinanciera, self::OsPendienteRevisionFinanciera => '#6610f2',
            self::Aprobada, self::OsAprobada                                       => '#20c997',
            self::Vigente, self::OsVigente                                         => '#198754',
            self::Cancelada, self::OsCancelada                                     => '#343a40',
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Clasificación
    // ─────────────────────────────────────────────────────────────────────

    /** Pertenece al flujo de actualizaciones (OS). */
    public function esActualizacion(): bool
    {
        return in_array($this, self::flujoActualizacion(), true);
    }

    /** El generador puede editar la ficha en este estado. */
    public function esEditable(): bool
    {
        return in_array($this, [
            self::Borrador,
            self::CorreccionRequerida,
            self::OsBorrador,
            self::OsCorreccionRequerida,
        ], true);
    }

    /** Estado terminal: no admite más transiciones automáticas del flujo. */
    public function esTerminal(): bool
    {
        return in_array($this, [
            self::Vigente,
            self::Cancelada,
            self::OsVigente,
            self::OsCancelada,
        ], true);
    }

    /** Se evalúa `fecha_fin` para determinar vigencia/vencimiento. */
    public function cuentaVigencia(): bool
    {
        return in_array($this, [
            self::Aprobada,
            self::Vigente,
            self::OsAprobada,
            self::OsVigente,
        ], true);
    }

    /** Hay una instancia de workflow activa esperando decisión. */
    public function enFlujoActivo(): bool
    {
        return in_array($this, [
            self::PendienteAutorizacion,
            self::PendienteRevisionFinanciera,
            self::OsPendienteAutorizacion,
            self::OsPendienteRevisionFinanciera,
        ], true);
    }

    /**
     * Admite que el generador solicite una modificación.
     *
     * La modificación no edita la ficha en sitio: crea una nueva versión (OS)
     * que recorre el flujo de aprobación completo, conservando la vigencia y
     * la trazabilidad de la versión actual hasta que la nueva sea aprobada.
     */
    public function permiteSolicitarModificacion(): bool
    {
        return in_array($this, [
            self::Aprobada,
            self::Vigente,
            self::OsAprobada,
            self::OsVigente,
        ], true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transiciones
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Transiciones válidas desde este estado.
     *
     * @return list<self>
     */
    public function transicionesPermitidas(): array
    {
        return match ($this) {
            self::Borrador => [
                self::PendienteAutorizacion,
                self::Cancelada,
            ],
            self::PendienteAutorizacion => [
                self::PendienteRevisionFinanciera,
                self::CorreccionRequerida,
                self::Cancelada,
            ],
            self::CorreccionRequerida => [
                self::PendienteAutorizacion,
                self::Cancelada,
            ],
            self::PendienteRevisionFinanciera => [
                self::Aprobada,
                self::CorreccionRequerida,
                self::Cancelada,
            ],
            self::Aprobada => [
                self::Vigente,
                self::Cancelada,
            ],
            // Terminal. La modificación se hace creando una nueva versión (OS),
            // no transicionando esta ficha.
            self::Vigente  => [self::Cancelada],
            self::Cancelada => [],

            self::OsBorrador => [
                self::OsPendienteAutorizacion,
                self::OsCancelada,
            ],
            self::OsPendienteAutorizacion => [
                self::OsPendienteRevisionFinanciera,
                self::OsCorreccionRequerida,
                self::OsCancelada,
            ],
            self::OsCorreccionRequerida => [
                self::OsPendienteAutorizacion,
                self::OsCancelada,
            ],
            self::OsPendienteRevisionFinanciera => [
                self::OsAprobada,
                self::OsCorreccionRequerida,
                self::OsCancelada,
            ],
            self::OsAprobada => [
                self::OsVigente,
                self::OsCancelada,
            ],
            self::OsVigente   => [self::OsCancelada],
            self::OsCancelada => [],
        };
    }

    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesPermitidas(), true);
    }

    /** Estado al enviar a autorización (desde borrador o corrección requerida). */
    public function estadoAlEnviar(): self
    {
        return $this->esActualizacion()
            ? self::OsPendienteAutorizacion
            : self::PendienteAutorizacion;
    }

    /** Estado al autorizar (nivel 1 — Dirección Médica). */
    public function estadoAlAutorizar(): self
    {
        return $this->esActualizacion()
            ? self::OsPendienteRevisionFinanciera
            : self::PendienteRevisionFinanciera;
    }

    /** Estado al aprobar (nivel 2 — Vicepresidencia Financiera). */
    public function estadoAlAprobar(): self
    {
        return $this->esActualizacion() ? self::OsAprobada : self::Aprobada;
    }

    /** Estado al rechazar, en cualquiera de los dos niveles. */
    public function estadoAlRechazar(): self
    {
        return $this->esActualizacion()
            ? self::OsCorreccionRequerida
            : self::CorreccionRequerida;
    }

    /** Estado al entrar en vigencia (cuando `fecha_ini` llega). */
    public function estadoAlEntrarEnVigencia(): self
    {
        return $this->esActualizacion() ? self::OsVigente : self::Vigente;
    }

    public function estadoAlCancelar(): self
    {
        return $this->esActualizacion() ? self::OsCancelada : self::Cancelada;
    }

    /** Estado inicial del flujo correspondiente. */
    public function estadoInicial(): self
    {
        return $this->esActualizacion() ? self::OsBorrador : self::Borrador;
    }

    /** Equivalente en el flujo de actualizaciones (OS). */
    public function equivalenteOs(): self
    {
        return match ($this) {
            self::Borrador                    => self::OsBorrador,
            self::PendienteAutorizacion       => self::OsPendienteAutorizacion,
            self::CorreccionRequerida         => self::OsCorreccionRequerida,
            self::PendienteRevisionFinanciera => self::OsPendienteRevisionFinanciera,
            self::Aprobada                    => self::OsAprobada,
            self::Vigente                     => self::OsVigente,
            self::Cancelada                   => self::OsCancelada,
            default                           => $this,
        };
    }

    /** Equivalente en el flujo de ficha original. */
    public function equivalenteFicha(): self
    {
        return match ($this) {
            self::OsBorrador                    => self::Borrador,
            self::OsPendienteAutorizacion       => self::PendienteAutorizacion,
            self::OsCorreccionRequerida         => self::CorreccionRequerida,
            self::OsPendienteRevisionFinanciera => self::PendienteRevisionFinanciera,
            self::OsAprobada                    => self::Aprobada,
            self::OsVigente                     => self::Vigente,
            self::OsCancelada                   => self::Cancelada,
            default                             => $this,
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Agrupaciones para listados, bandejas y dashboard
    // ─────────────────────────────────────────────────────────────────────

    /** @return list<self> */
    public static function borradores(): array
    {
        return [self::Borrador, self::OsBorrador];
    }

    /** @return list<self> */
    public static function pendientesAutorizacion(): array
    {
        return [self::PendienteAutorizacion, self::OsPendienteAutorizacion];
    }

    /** @return list<self> */
    public static function pendientesRevisionFinanciera(): array
    {
        return [self::PendienteRevisionFinanciera, self::OsPendienteRevisionFinanciera];
    }

    /** @return list<self> */
    public static function correccionRequerida(): array
    {
        return [self::CorreccionRequerida, self::OsCorreccionRequerida];
    }

    /** @return list<self> */
    public static function aprobadas(): array
    {
        return [self::Aprobada, self::OsAprobada];
    }

    /** @return list<self> */
    public static function vigentesEstados(): array
    {
        return [self::Vigente, self::OsVigente];
    }

    /** @return list<self> */
    public static function canceladas(): array
    {
        return [self::Cancelada, self::OsCancelada];
    }

    /**
     * Estados con una instancia de workflow esperando decisión.
     *
     * @return list<self>
     */
    public static function enProceso(): array
    {
        return [
            self::PendienteAutorizacion,
            self::PendienteRevisionFinanciera,
            self::OsPendienteAutorizacion,
            self::OsPendienteRevisionFinanciera,
        ];
    }

    /** Bandeja del Director Médico. @return list<self> */
    public static function porAutorizar(): array
    {
        return self::pendientesAutorizacion();
    }

    /** Bandeja del VP Financiero. @return list<self> */
    public static function porAprobar(): array
    {
        return self::pendientesRevisionFinanciera();
    }

    /** Bandeja de devoluciones del generador. @return list<self> */
    public static function rechazadas(): array
    {
        return self::correccionRequerida();
    }

    /**
     * Estados que cuentan para vigencia (aprobada + vigente).
     *
     * Sustituye a la antigua `finalizadas()`: una ficha aprobada ya es
     * contractualmente válida, y pasa a `vigente` cuando arranca su vigencia.
     *
     * @return list<self>
     */
    public static function finalizadas(): array
    {
        return [self::Aprobada, self::Vigente, self::OsAprobada, self::OsVigente];
    }

    /** @return list<self> */
    public static function flujoActualizacion(): array
    {
        return [
            self::OsBorrador,
            self::OsPendienteAutorizacion,
            self::OsCorreccionRequerida,
            self::OsPendienteRevisionFinanciera,
            self::OsAprobada,
            self::OsVigente,
            self::OsCancelada,
        ];
    }

    /** @return list<self> */
    public static function editables(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $e): bool => $e->esEditable()
        ));
    }

    /**
     * IDs de un grupo de estados, listos para un whereIn.
     *
     * @param  list<self>  $estados
     * @return list<int>
     */
    public static function ids(array $estados): array
    {
        return array_map(static fn (self $e): int => $e->id(), $estados);
    }

    /**
     * Códigos de un grupo de estados, para consultas sobre las vistas SQL.
     *
     * @param  list<self>  $estados
     * @return list<string>
     */
    public static function codigos(array $estados): array
    {
        return array_map(static fn (self $e): string => $e->value, $estados);
    }
}
