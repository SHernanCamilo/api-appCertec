<?php

declare(strict_types=1);

namespace Tests\Unit\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Reglas del workflow rediseñado de fichas técnicas.
 *
 * Cubre la regla de flujo secuencial que en el sistema JADE legacy solo
 * existía como documentación: `insert_aprob.php` aceptaba cualquier
 * `id_estado` recibido por POST.
 */
final class EstadoFichaTest extends TestCase
{
    /** Los IDs son la PK de `fich_estados` y no deben cambiar sin migración. */
    public function testIdsDelCatalogo(): void
    {
        $esperado = [
            'borrador'                      => 1,
            'pendiente_autorizacion'        => 2,
            'correccion_requerida'          => 3,
            'pendiente_revision_financiera' => 4,
            'aprobada'                      => 5,
            'vigente'                       => 6,
            'cancelada'                     => 7,

            'os_borrador'                      => 8,
            'os_pendiente_autorizacion'        => 9,
            'os_correccion_requerida'          => 10,
            'os_pendiente_revision_financiera' => 11,
            'os_aprobada'                      => 12,
            'os_vigente'                       => 13,
            'os_cancelada'                     => 14,
        ];

        foreach ($esperado as $codigo => $id) {
            $this->assertSame($id, EstadoFicha::from($codigo)->id(), "Estado {$codigo}");
        }

        $this->assertCount(count($esperado), EstadoFicha::cases());
    }

    public function testFromIdRecuperaElCaso(): void
    {
        $this->assertSame(EstadoFicha::Aprobada, EstadoFicha::fromId(5));
        $this->assertSame(EstadoFicha::OsVigente, EstadoFicha::fromId(13));
    }

    public function testFromIdLanzaErrorConIdDesconocido(): void
    {
        $this->expectException(ValueError::class);
        EstadoFicha::fromId(99);
    }

    public function testTryFromIdDevuelveNullSinLanzar(): void
    {
        $this->assertNull(EstadoFicha::tryFromId(99));
        $this->assertSame(EstadoFicha::Vigente, EstadoFicha::tryFromId(6));
    }

    // ── Flujo secuencial ─────────────────────────────────────────────────

    /** La doble validación es obligatoria: no se salta ningún nivel. */
    public function testNoSePuedeSaltarLaAutorizacion(): void
    {
        $this->assertFalse(EstadoFicha::Borrador->puedeTransicionarA(EstadoFicha::Aprobada));
        $this->assertFalse(EstadoFicha::Borrador->puedeTransicionarA(EstadoFicha::PendienteRevisionFinanciera));
        $this->assertFalse(EstadoFicha::Borrador->puedeTransicionarA(EstadoFicha::Vigente));

        $this->assertFalse(EstadoFicha::OsBorrador->puedeTransicionarA(EstadoFicha::OsAprobada));
    }

    public function testFlujoCompletoDeFichaNueva(): void
    {
        $this->assertTrue(EstadoFicha::Borrador->puedeTransicionarA(EstadoFicha::PendienteAutorizacion));
        $this->assertTrue(EstadoFicha::PendienteAutorizacion->puedeTransicionarA(EstadoFicha::PendienteRevisionFinanciera));
        $this->assertTrue(EstadoFicha::PendienteRevisionFinanciera->puedeTransicionarA(EstadoFicha::Aprobada));
        $this->assertTrue(EstadoFicha::Aprobada->puedeTransicionarA(EstadoFicha::Vigente));
    }

    public function testFlujoCompletoDeActualizacion(): void
    {
        $this->assertTrue(EstadoFicha::OsBorrador->puedeTransicionarA(EstadoFicha::OsPendienteAutorizacion));
        $this->assertTrue(EstadoFicha::OsPendienteAutorizacion->puedeTransicionarA(EstadoFicha::OsPendienteRevisionFinanciera));
        $this->assertTrue(EstadoFicha::OsPendienteRevisionFinanciera->puedeTransicionarA(EstadoFicha::OsAprobada));
        $this->assertTrue(EstadoFicha::OsAprobada->puedeTransicionarA(EstadoFicha::OsVigente));
    }

    /** Un rechazo en cualquier nivel devuelve la ficha al generador. */
    public function testRechazoEnAmbosNivelesLlevaACorreccionRequerida(): void
    {
        $this->assertTrue(
            EstadoFicha::PendienteAutorizacion->puedeTransicionarA(EstadoFicha::CorreccionRequerida)
        );
        $this->assertTrue(
            EstadoFicha::PendienteRevisionFinanciera->puedeTransicionarA(EstadoFicha::CorreccionRequerida)
        );

        $this->assertSame(
            EstadoFicha::CorreccionRequerida,
            EstadoFicha::PendienteAutorizacion->estadoAlRechazar()
        );
        $this->assertSame(
            EstadoFicha::CorreccionRequerida,
            EstadoFicha::PendienteRevisionFinanciera->estadoAlRechazar()
        );
    }

    /** Tras corregir, la ficha vuelve directo a autorización (reinicia el ciclo). */
    public function testCorreccionRequeridaReiniciaElFlujo(): void
    {
        $this->assertTrue(
            EstadoFicha::CorreccionRequerida->puedeTransicionarA(EstadoFicha::PendienteAutorizacion)
        );
        $this->assertTrue(
            EstadoFicha::OsCorreccionRequerida->puedeTransicionarA(EstadoFicha::OsPendienteAutorizacion)
        );

        $this->assertSame(
            EstadoFicha::PendienteAutorizacion,
            EstadoFicha::CorreccionRequerida->estadoAlEnviar()
        );
    }

    public function testNoSePuedeVolverAtrasDesdeVigente(): void
    {
        $this->assertFalse(EstadoFicha::Vigente->puedeTransicionarA(EstadoFicha::Borrador));
        $this->assertFalse(EstadoFicha::Vigente->puedeTransicionarA(EstadoFicha::PendienteAutorizacion));
        $this->assertFalse(EstadoFicha::Vigente->puedeTransicionarA(EstadoFicha::Aprobada));
    }

    public function testCanceladaEsTerminalAbsoluto(): void
    {
        $this->assertSame([], EstadoFicha::Cancelada->transicionesPermitidas());
        $this->assertSame([], EstadoFicha::OsCancelada->transicionesPermitidas());
    }

    /** Toda ficha en curso se puede cancelar. */
    public function testSePuedeCancelarDesdeCualquierEstadoNoTerminal(): void
    {
        foreach (EstadoFicha::cases() as $estado) {
            if (in_array($estado, EstadoFicha::canceladas(), true)) {
                continue;
            }

            $this->assertTrue(
                $estado->puedeTransicionarA($estado->estadoAlCancelar()),
                "Debería poder cancelarse desde {$estado->value}"
            );
        }
    }

    // ── Modificación de fichas formalizadas ──────────────────────────────

    /**
     * Una ficha vigente no se edita en sitio: se solicita una modificación que
     * crea una nueva versión y reinicia el flujo.
     */
    public function testSoloAprobadaYVigentePermitenSolicitarModificacion(): void
    {
        $permitidos = [
            EstadoFicha::Aprobada,
            EstadoFicha::Vigente,
            EstadoFicha::OsAprobada,
            EstadoFicha::OsVigente,
        ];

        foreach (EstadoFicha::cases() as $estado) {
            $this->assertSame(
                in_array($estado, $permitidos, true),
                $estado->permiteSolicitarModificacion(),
                "permiteSolicitarModificacion() para {$estado->value}"
            );
        }
    }

    public function testFichaVigenteNoEsEditableEnSitio(): void
    {
        $this->assertFalse(EstadoFicha::Vigente->esEditable());
        $this->assertFalse(EstadoFicha::Aprobada->esEditable());
    }

    public function testEsEditableSoloEnBorradorYCorreccion(): void
    {
        $editables = [
            EstadoFicha::Borrador,
            EstadoFicha::CorreccionRequerida,
            EstadoFicha::OsBorrador,
            EstadoFicha::OsCorreccionRequerida,
        ];

        foreach (EstadoFicha::cases() as $estado) {
            $this->assertSame(
                in_array($estado, $editables, true),
                $estado->esEditable(),
                "esEditable() para {$estado->value}"
            );
        }
    }

    // ── Separación de flujos ─────────────────────────────────────────────

    public function testLosFlujosNoSeCruzan(): void
    {
        $this->assertSame(
            EstadoFicha::OsPendienteRevisionFinanciera,
            EstadoFicha::OsPendienteAutorizacion->estadoAlAutorizar()
        );
        $this->assertSame(
            EstadoFicha::PendienteRevisionFinanciera,
            EstadoFicha::PendienteAutorizacion->estadoAlAutorizar()
        );

        $this->assertSame(
            EstadoFicha::OsAprobada,
            EstadoFicha::OsPendienteRevisionFinanciera->estadoAlAprobar()
        );
        $this->assertSame(
            EstadoFicha::Aprobada,
            EstadoFicha::PendienteRevisionFinanciera->estadoAlAprobar()
        );

        $this->assertSame(
            EstadoFicha::OsCorreccionRequerida,
            EstadoFicha::OsPendienteAutorizacion->estadoAlRechazar()
        );
    }

    public function testEquivalenciasEntreFlujos(): void
    {
        $this->assertSame(EstadoFicha::OsVigente, EstadoFicha::Vigente->equivalenteOs());
        $this->assertSame(EstadoFicha::Vigente, EstadoFicha::OsVigente->equivalenteFicha());
        $this->assertSame(EstadoFicha::OsBorrador, EstadoFicha::Borrador->equivalenteOs());
    }

    public function testEsActualizacionIdentificaElFlujoOs(): void
    {
        foreach (EstadoFicha::flujoActualizacion() as $estado) {
            $this->assertTrue($estado->esActualizacion(), "{$estado->value} debería ser del flujo OS");
        }

        $this->assertFalse(EstadoFicha::Borrador->esActualizacion());
        $this->assertFalse(EstadoFicha::Vigente->esActualizacion());
    }

    // ── Agrupaciones y metadatos ─────────────────────────────────────────

    public function testAgrupacionesDeBandeja(): void
    {
        $this->assertSame([1, 8], EstadoFicha::ids(EstadoFicha::borradores()));
        $this->assertSame([2, 9], EstadoFicha::ids(EstadoFicha::pendientesAutorizacion()));
        $this->assertSame([3, 10], EstadoFicha::ids(EstadoFicha::correccionRequerida()));
        $this->assertSame([4, 11], EstadoFicha::ids(EstadoFicha::pendientesRevisionFinanciera()));
        $this->assertSame([5, 12], EstadoFicha::ids(EstadoFicha::aprobadas()));
        $this->assertSame([6, 13], EstadoFicha::ids(EstadoFicha::vigentesEstados()));
        $this->assertSame([7, 14], EstadoFicha::ids(EstadoFicha::canceladas()));

        // "finalizadas" agrupa lo que tiene valor contractual.
        $this->assertSame([5, 6, 12, 13], EstadoFicha::ids(EstadoFicha::finalizadas()));

        // La bandeja de devoluciones conserva el nombre histórico.
        $this->assertSame(
            EstadoFicha::ids(EstadoFicha::correccionRequerida()),
            EstadoFicha::ids(EstadoFicha::rechazadas())
        );
    }

    public function testEnProcesoSonLosEstadosConDecisionPendiente(): void
    {
        $this->assertSame([2, 4, 9, 11], EstadoFicha::ids(EstadoFicha::enProceso()));

        foreach (EstadoFicha::enProceso() as $estado) {
            $this->assertTrue($estado->enFlujoActivo(), "{$estado->value} debería estar en flujo activo");
        }
    }

    public function testCuentaVigenciaSoloEnAprobadaYVigente(): void
    {
        $conVigencia = [
            EstadoFicha::Aprobada,
            EstadoFicha::Vigente,
            EstadoFicha::OsAprobada,
            EstadoFicha::OsVigente,
        ];

        foreach (EstadoFicha::cases() as $estado) {
            $this->assertSame(
                in_array($estado, $conVigencia, true),
                $estado->cuentaVigencia(),
                "cuentaVigencia() para {$estado->value}"
            );
        }
    }

    public function testCodigosDevuelveLosSlugs(): void
    {
        $this->assertSame(
            ['aprobada', 'vigente', 'os_aprobada', 'os_vigente'],
            EstadoFicha::codigos(EstadoFicha::finalizadas())
        );
    }

    public function testTodosLosCasosTienenEtiquetaYColor(): void
    {
        foreach (EstadoFicha::cases() as $estado) {
            $this->assertNotSame('', $estado->label(), "Falta etiqueta para {$estado->value}");
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/i',
                $estado->colorHex(),
                "Color inválido para {$estado->value}"
            );
        }
    }
}
