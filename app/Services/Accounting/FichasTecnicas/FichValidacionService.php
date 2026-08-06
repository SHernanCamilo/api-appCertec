<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use App\Exceptions\FichasTecnicas\TransicionEstadoInvalidaException;
use App\Models\Accounting\FichasTecnicas\FichComentario;
use App\Models\Accounting\FichasTecnicas\FichFicha;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Flujo de doble validación de fichas técnicas.
 *
 *   borrador ──enviar──► pendiente_autorizacion ──autorizar──► pendiente_revision_financiera
 *                              │ rechazar                          │ aprobar   │ rechazar
 *                              ▼                                   ▼           ▼
 *                     correccion_requerida ◄──────────────── aprobada    correccion_requerida
 *                              │ reenviar                        │ (fecha_ini)
 *                              └──► pendiente_autorizacion       ▼
 *                                                             vigente
 *
 * Reemplaza `autorizador/insert_val.php` y `aprobador/insert_aprob.php`.
 *
 * Diferencias frente al legacy:
 *  - Las transiciones se validan contra el enum `EstadoFicha`. El legacy aceptaba
 *    cualquier `id_estado` por POST, con lo que se podía enviar `id_estado=5`
 *    sobre un borrador y saltarse la autorización.
 *  - El consecutivo se calcula y valida en el servidor; el legacy lo tomaba
 *    literal del campo `num_ficha` sin comprobar duplicados.
 *  - Cada decisión queda registrada además en el motor de flujos, de modo que la
 *    trazabilidad es consultable junto con la del resto de los módulos.
 *  - El correo se envía después del commit: un fallo de SMTP ya no revierte la
 *    validación (en el legacy el error quedaba solo en un .txt).
 */
final class FichValidacionService
{
    public function __construct(
        private readonly FichConsecutivoService $consecutivos,
        private readonly FichNotificacionService $notificaciones,
        private readonly FichAuditoriaService $auditoria,
        private readonly FichVentanaEnvioService $ventana,
        private readonly FichWorkflowBridge $workflow,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────
    // Entrada al flujo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * El generador envía la ficha a autorización.
     *
     * Aplica RN-03 (ventana de envío) y exige que la ficha esté completa.
     * Sirve tanto para el primer envío como para el reenvío tras una
     * corrección: en ambos casos se abre un nuevo ciclo de flujo.
     */
    public function enviar(int $idFicha, int $usuarioId, ?string $observacion = null): FichFicha
    {
        $ficha   = FichFicha::query()->findOrFail($idFicha);
        $origen  = $ficha->estadoEnum();
        $destino = $origen->estadoAlEnviar();

        $this->garantizarTransicion($origen, $destino);
        $this->ventana->garantizarAbierta();
        $this->garantizarFichaCompleta($ficha);

        $esReenvio = in_array($origen, EstadoFicha::correccionRequerida(), true);
        $nota      = $observacion !== null && trim($observacion) !== ''
            ? trim($observacion)
            : ($esReenvio ? 'Ficha corregida y reenviada a autorización' : 'Ficha enviada a autorización');

        $ficha = DB::transaction(function () use ($ficha, $usuarioId, $destino, $nota): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, $nota);

            $ficha->update([
                'id_estado'         => $destino->id(),
                'fecha_envio_flujo' => now(),
                'ciclos_flujo'      => (int) $ficha->ciclos_flujo + 1,
            ]);

            $this->registrarComentario($ficha, $usuarioId, $destino, $nota);

            return $ficha->refresh();
        });

        // Abre (o reabre) la instancia en el motor de flujos.
        $this->workflow->iniciar($ficha, $usuarioId, $nota);

        $this->notificarSinRomper($ficha->refresh(), $destino, $nota);

        return $ficha->refresh();
    }

    /**
     * Alias de `enviar()` para el reenvío tras corrección.
     *
     * Se conserva como método propio porque es la acción que expone el frontend
     * en la bandeja de devoluciones.
     */
    public function reenviar(int $idFicha, int $usuarioId, ?string $observacion = null): FichFicha
    {
        return $this->enviar($idFicha, $usuarioId, $observacion);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Nivel 1 — Dirección Médica
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Autoriza la ficha y la remite a revisión financiera.
     */
    public function autorizar(int $idFicha, int $usuarioId, string $observacion): FichFicha
    {
        $observacion = trim($observacion);

        if ($observacion === '') {
            throw new RuntimeException('El comentario de autorización es obligatorio.');
        }

        $ficha   = FichFicha::query()->findOrFail($idFicha);
        $origen  = $ficha->estadoEnum();
        $destino = $origen->estadoAlAutorizar();

        $this->garantizarTransicion($origen, $destino);

        $ficha = DB::transaction(function () use ($ficha, $usuarioId, $observacion, $destino): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, $observacion);

            $ficha->update([
                'id_estado'        => $destino->id(),
                'user_autoriza_id' => $usuarioId,
                'fecha_autoriza'   => now(),
                'obs_autoriza'     => $observacion,
            ]);

            $this->registrarComentario($ficha, $usuarioId, $destino, $observacion);

            return $ficha->refresh();
        });

        $this->workflow->autorizar($ficha, $usuarioId, $observacion);
        $this->notificarSinRomper($ficha, $destino, $observacion);

        return $ficha;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Nivel 2 — Vicepresidencia Financiera
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Aprueba la ficha, le asigna consecutivo y la formaliza.
     *
     * Si la ficha es una actualización (OS) aprobada, la versión anterior queda
     * enlazada mediante `reemplazada_por_id`, de modo que el historial de
     * vigencias de la ficha original se conserva íntegro.
     *
     * @param  string|null  $consecutivoManual  Consecutivo digitado por el
     *                      aprobador. Si es null se calcula automáticamente.
     */
    public function aprobar(
        int $idFicha,
        int $usuarioId,
        ?string $observacion = null,
        ?string $consecutivoManual = null,
    ): FichFicha {
        $ficha   = FichFicha::query()->findOrFail($idFicha);
        $origen  = $ficha->estadoEnum();
        $destino = $origen->estadoAlAprobar();

        $this->garantizarTransicion($origen, $destino);

        $ficha = DB::transaction(function () use ($ficha, $usuarioId, $observacion, $destino, $consecutivoManual): FichFicha {
            $consecutivo = $this->resolverConsecutivo($ficha, $consecutivoManual);

            $nota = $observacion !== null && trim($observacion) !== ''
                ? trim($observacion)
                : "Ficha aprobada con consecutivo {$consecutivo}";

            $this->auditoria->marcarUsuario($usuarioId, $nota);

            $ficha->update([
                'id_estado'       => $destino->id(),
                'consecutivo'     => $consecutivo,
                'user_aprueba_id' => $usuarioId,
                'fecha_aprueba'   => now(),
                'obs_aprueba'     => $observacion,
            ]);

            $this->registrarComentario($ficha, $usuarioId, $destino, $nota);

            // Cierra el ciclo de vida de la versión que esta ficha reemplaza.
            $this->enlazarVersionAnterior($ficha, $usuarioId);

            return $ficha->refresh();
        });

        $this->workflow->aprobar($ficha, $usuarioId, $observacion);
        $this->notificarSinRomper($ficha, $destino, $observacion ?? '');

        // Si la vigencia ya arrancó, la ficha pasa a vigente de inmediato.
        if ($ficha->vigenciaIniciada()) {
            $ficha = $this->promoverAVigencia($ficha, $usuarioId);
        }

        return $ficha;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rechazo y vigencia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Devuelve la ficha al generador para corrección, desde cualquiera de los
     * dos niveles de validación.
     */
    public function rechazar(int $idFicha, int $usuarioId, string $motivo, bool $esAprobador = false): FichFicha
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('El motivo de la devolución es obligatorio.');
        }

        $ficha   = FichFicha::query()->findOrFail($idFicha);
        $origen  = $ficha->estadoEnum();
        $destino = $origen->estadoAlRechazar();

        $this->garantizarTransicion($origen, $destino);

        // El nivel se deduce del estado de origen, no de un flag del cliente.
        $enRevisionFinanciera = in_array($origen, EstadoFicha::pendientesRevisionFinanciera(), true);
        $registraComoAprobador = $esAprobador || $enRevisionFinanciera;

        $ficha = DB::transaction(function () use ($ficha, $usuarioId, $motivo, $destino, $registraComoAprobador): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, $motivo);

            $ficha->update([
                'id_estado' => $destino->id(),
            ] + ($registraComoAprobador
                ? ['user_aprueba_id' => $usuarioId, 'fecha_aprueba' => now(), 'obs_aprueba' => $motivo]
                : ['user_autoriza_id' => $usuarioId, 'fecha_autoriza' => now(), 'obs_autoriza' => $motivo]
            ));

            $this->registrarComentario($ficha, $usuarioId, $destino, $motivo);

            return $ficha->refresh();
        });

        $this->workflow->rechazar($ficha, $usuarioId, $motivo);
        $this->notificarSinRomper($ficha, $destino, $motivo);

        return $ficha;
    }

    /**
     * Promueve una ficha aprobada a vigente.
     *
     * Se invoca al aprobar (si la vigencia ya arrancó) y desde el comando
     * programado `fichas:actualizar-vigencias` para las fichas cuya `fecha_ini`
     * llega después de la aprobación.
     */
    public function promoverAVigencia(FichFicha $ficha, ?int $usuarioId = null): FichFicha
    {
        $origen  = $ficha->estadoEnum();
        $destino = $origen->estadoAlEntrarEnVigencia();

        if ($origen === $destino) {
            return $ficha;
        }

        $this->garantizarTransicion($origen, $destino);

        return DB::transaction(function () use ($ficha, $usuarioId, $destino): FichFicha {
            $this->auditoria->marcarUsuario(
                $usuarioId ?? (int) $ficha->user_aprueba_id,
                'La ficha entró en vigencia'
            );

            $ficha->update([
                'id_estado'             => $destino->id(),
                'fecha_vigencia_inicio' => now(),
            ]);

            return $ficha->refresh();
        });
    }

    /**
     * El generador solicita modificar una ficha aprobada o vigente.
     *
     * No se edita la ficha en sitio: se crea una nueva versión (OS) que recorre
     * el flujo completo de aprobación. La versión actual mantiene su vigencia y
     * su trazabilidad hasta que la nueva sea aprobada, momento en el que queda
     * enlazada mediante `reemplazada_por_id`.
     *
     * @param  array<string, mixed>  $cambios
     */
    public function solicitarModificacion(
        int $idFicha,
        int $usuarioId,
        string $motivo,
        array $cambios,
        FichFichaService $fichas,
    ): FichFicha {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException(
                'Debe indicar el motivo de la modificación: queda registrado en la trazabilidad de la ficha.'
            );
        }

        $ficha = FichFicha::query()->findOrFail($idFicha);

        if (! $ficha->permiteSolicitarModificacion()) {
            throw new RuntimeException(sprintf(
                'La ficha está en estado "%s" y no admite solicitud de modificación.%s',
                $ficha->estadoEnum()->label(),
                $ficha->reemplazada_por_id !== null
                    ? ' Ya existe una versión posterior que la reemplaza.'
                    : ''
            ));
        }

        // La ventana de envío también aplica a las modificaciones.
        $this->ventana->garantizarAbierta();

        $nueva = $fichas->crearActualizacion(
            $ficha->id,
            $cambios + ['obs_os' => $motivo, 'motivo_modificacion' => $motivo],
            $usuarioId
        );

        Log::channel('daily')->info('Fichas Técnicas: solicitud de modificación creada', [
            'ficha_origen' => $ficha->id,
            'nueva_version' => $nueva->id,
            'version'      => $nueva->version,
            'usuario_id'   => $usuarioId,
        ]);

        return $nueva;
    }

    /**
     * Consecutivo sugerido para el formulario del aprobador.
     */
    public function consecutivoSugerido(int $idFicha): string
    {
        return $this->consecutivos->resolverParaFicha(FichFicha::query()->findOrFail($idFicha));
    }

    /**
     * Acciones que el usuario puede ejecutar sobre la ficha en su estado actual.
     *
     * Centraliza en el backend la decisión de qué botones habilitar, en lugar de
     * replicar la matriz de permisos en el frontend.
     *
     * @param  list<string>  $roles  Roles Spatie del usuario
     * @return array<string, mixed>
     */
    public function accionesDisponibles(FichFicha $ficha, array $roles, int $usuarioId): array
    {
        $estado    = $ficha->estadoEnum();
        $config    = config('fichas_tecnicas.roles');
        $esPropia  = (int) $ficha->id_user_reg === $usuarioId;

        $esGenerador   = in_array($config['generador'], $roles, true);
        $esAutorizador = in_array($config['autorizador'], $roles, true);
        $esAprobador   = in_array($config['aprobador'], $roles, true);

        $ventana = $this->ventana->estado();

        return [
            'editar' => $estado->esEditable() && ($esPropia || $esGenerador),

            'enviar' => $estado->puedeTransicionarA($estado->estadoAlEnviar())
                && ($esPropia || $esGenerador)
                && $ventana['abierta']
                && $ficha->total_detalles > 0,

            'autorizar' => in_array($estado, EstadoFicha::pendientesAutorizacion(), true) && $esAutorizador,

            'aprobar' => in_array($estado, EstadoFicha::pendientesRevisionFinanciera(), true) && $esAprobador,

            'rechazar' => (
                (in_array($estado, EstadoFicha::pendientesAutorizacion(), true) && $esAutorizador)
                || (in_array($estado, EstadoFicha::pendientesRevisionFinanciera(), true) && $esAprobador)
            ),

            'solicitar_modificacion' => $ficha->permiteSolicitarModificacion() && ($esPropia || $esGenerador),

            'cancelar' => $estado->puedeTransicionarA($estado->estadoAlCancelar())
                && ($esPropia || $esGenerador || $esAprobador),

            'descargar_pdf' => $ficha->consecutivo !== null && $ficha->consecutivo !== '',

            'ventana_envio' => $ventana,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internos
    // ─────────────────────────────────────────────────────────────────────

    private function garantizarTransicion(EstadoFicha $origen, EstadoFicha $destino): void
    {
        if (! $origen->puedeTransicionarA($destino)) {
            throw new TransicionEstadoInvalidaException($origen, $destino);
        }
    }

    /** Una ficha no entra al flujo sin servicios ni profesionales. */
    private function garantizarFichaCompleta(FichFicha $ficha): void
    {
        if ((int) $ficha->total_detalles === 0) {
            throw new RuntimeException(
                'La ficha debe tener al menos un servicio antes de enviarla a autorización.'
            );
        }

        if ((int) $ficha->total_profesionales === 0) {
            throw new RuntimeException(
                'La ficha debe tener al menos un profesional asignado antes de enviarla a autorización.'
            );
        }
    }

    private function resolverConsecutivo(FichFicha $ficha, ?string $consecutivoManual): string
    {
        $manual = $consecutivoManual !== null ? trim($consecutivoManual) : '';

        if ($manual === '') {
            return $this->consecutivos->resolverParaFicha($ficha);
        }

        if (! $this->consecutivos->estaDisponible($manual, $ficha->id)) {
            throw new RuntimeException("El consecutivo \"{$manual}\" ya está asignado a otra ficha.");
        }

        return $manual;
    }

    /**
     * Enlaza la versión anterior con la recién aprobada.
     *
     * Así queda registrado qué ficha reemplazó a cuál, sin perder el histórico
     * de vigencia de la versión anterior.
     */
    private function enlazarVersionAnterior(FichFicha $ficha, int $usuarioId): void
    {
        if ($ficha->id_padre === null) {
            return;
        }

        $padre = FichFicha::query()->find($ficha->id_padre);

        if ($padre === null) {
            return;
        }

        $this->auditoria->marcarUsuario(
            $usuarioId,
            "Reemplazada por la versión {$ficha->version} (ficha {$ficha->consecutivo})"
        );

        $padre->forceFill(['reemplazada_por_id' => $ficha->id])->saveQuietly();
    }

    private function registrarComentario(
        FichFicha $ficha,
        int $usuarioId,
        EstadoFicha $estado,
        string $descripcion,
    ): void {
        FichComentario::query()->create([
            'id_ficha'    => $ficha->id,
            'id_usuario'  => $usuarioId,
            'id_estado'   => $estado->id(),
            'descripcion' => $descripcion,
        ]);
    }

    /**
     * El correo se envía después del commit. Un fallo de SMTP se registra pero
     * no revierte la validación ya persistida.
     */
    private function notificarSinRomper(FichFicha $ficha, EstadoFicha $estado, string $observacion): void
    {
        try {
            $this->notificaciones->notificarCambioEstado($ficha, $estado, $observacion);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Fichas Técnicas: falló la notificación de cambio de estado', [
                'id_ficha' => $ficha->id,
                'estado'   => $estado->value,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
