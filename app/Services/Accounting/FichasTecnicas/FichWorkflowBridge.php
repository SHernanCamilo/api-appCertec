<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfModulo;
use App\Services\Workflow\WorkflowExecutor;
use App\Services\Workflow\WorkflowNotifier;
use App\Services\Workflow\WorkflowResolver;
use Database\Seeders\FichasTecnicasWorkflowSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Puente entre el módulo Fichas Técnicas y el motor de flujos genérico.
 *
 * Reparto de responsabilidades (integración híbrida):
 *
 *  - La máquina de estados de la ficha (`EstadoFicha`) es la fuente de verdad
 *    del negocio: valida transiciones y decide qué se puede hacer.
 *  - El motor de flujos (`wf_*`) aporta la trazabilidad transversal, la
 *    resolución dinámica de aprobadores y las notificaciones internas.
 *
 * Por eso todas las operaciones de este puente son *best-effort*: si el motor
 * falla (flujo sin configurar, aprobador no resuelto, etc.) se registra el
 * incidente pero no se aborta la validación de la ficha, que ya fue autorizada
 * por su propia máquina de estados. Así el módulo sigue operativo aunque la
 * parametrización del flujo esté incompleta.
 */
final class FichWorkflowBridge
{
    public function __construct(
        private readonly WorkflowResolver $resolver,
        private readonly WorkflowExecutor $executor,
        private readonly WorkflowNotifier $notifier,
    ) {
    }

    /**
     * Inicia (o reinicia) el flujo de aprobación de una ficha.
     *
     * Si la ficha ya tenía una instancia abierta —caso del reenvío tras una
     * corrección— se cancela primero, de modo que el historial conserve todos
     * los ciclos por los que pasó la ficha.
     *
     * @return int|null ID de la instancia creada, o null si no se pudo iniciar
     */
    public function iniciar(FichFicha $ficha, int $usuarioId, ?string $observacion = null): ?int
    {
        try {
            $this->cerrarInstanciaAbierta($ficha, $usuarioId, 'Reinicio del flujo por nueva solicitud');

            $flujo = $this->resolver->resolverFlujo(
                FichasTecnicasWorkflowSeeder::MODULO,
                $this->contexto($ficha)
            );

            $instancia = $this->executor->iniciarFlujo(
                $flujo,
                $usuarioId,
                $this->contexto($ficha),
                $ficha->consecutivo
            );

            $ficha->forceFill(['wf_instancia_id' => $instancia->id])->saveQuietly();

            Log::channel('daily')->info('Fichas Técnicas: flujo iniciado', [
                'id_ficha'     => $ficha->id,
                'instancia_id' => $instancia->id,
                'flujo'        => $flujo->codigo,
                'ciclo'        => $ficha->ciclos_flujo,
            ]);

            return (int) $instancia->id;
        } catch (\Throwable $e) {
            $this->registrarFallo('iniciar', $ficha, $e);

            return null;
        }
    }

    /** Registra en el motor la autorización del nivel 1 y avanza al paso 2. */
    public function autorizar(FichFicha $ficha, int $usuarioId, string $observacion): void
    {
        $this->avanzar($ficha, $usuarioId, $observacion, 'autorizar');
    }

    /** Registra en el motor la aprobación del nivel 2 y completa la instancia. */
    public function aprobar(FichFicha $ficha, int $usuarioId, ?string $observacion): void
    {
        $this->avanzar($ficha, $usuarioId, $observacion ?? 'Ficha aprobada', 'aprobar');
    }

    /** Registra el rechazo y cierra la instancia como rechazada. */
    public function rechazar(FichFicha $ficha, int $usuarioId, string $motivo): void
    {
        $instancia = $this->instanciaAbierta($ficha);

        if ($instancia === null) {
            return;
        }

        try {
            $this->executor->rechazar((int) $instancia->id, $usuarioId, $motivo);
        } catch (\Throwable $e) {
            // El usuario puede no estar en la lista de aprobadores resueltos por
            // el motor (p. ej. alcance mal parametrizado). La decisión ya fue
            // validada por la máquina de estados, así que se deja constancia
            // como observación en lugar de perder la traza.
            $this->registrarObservacionDeRespaldo($instancia, $usuarioId, "RECHAZO: {$motivo}", $e);
        }
    }

    /** Cancela la instancia cuando la ficha se cancela. */
    public function cancelar(FichFicha $ficha, int $usuarioId, string $motivo): void
    {
        $this->cerrarInstanciaAbierta($ficha, $usuarioId, $motivo);
    }

    /**
     * Aprobadores que el motor resuelve para el paso actual de la ficha.
     *
     * El frontend lo usa para mostrar "pendiente de: {nombres}".
     *
     * @return Collection<int, \App\Models\User>
     */
    public function aprobadoresActuales(FichFicha $ficha): Collection
    {
        $instancia = $this->instanciaAbierta($ficha);

        if ($instancia === null) {
            return collect();
        }

        try {
            return $this->notifier->resolverAprobadores($instancia);
        } catch (\Throwable $e) {
            $this->registrarFallo('aprobadoresActuales', $ficha, $e);

            return collect();
        }
    }

    /**
     * Traza completa del flujo: instancia actual + todas las aprobaciones de
     * todos los ciclos por los que pasó la ficha.
     *
     * @return array<string, mixed>
     */
    public function trazabilidad(FichFicha $ficha): array
    {
        $moduloId = $this->moduloId();

        if ($moduloId === null) {
            return ['instancias' => [], 'paso_actual' => null];
        }

        $instancias = WfInstancia::query()
            ->with([
                'definicion:id,codigo,nombre',
                'pasoActual:id,orden,nombre_paso,rol_aprobador',
                'aprobaciones.user:id,name,email',
                'aprobaciones.paso:id,orden,nombre_paso',
                'solicitante:id,name,email',
            ])
            ->where('id_modulo', $moduloId)
            ->where('modulo_record_id', $ficha->id)
            ->orderBy('id')
            ->get();

        $abierta = $instancias->firstWhere('estado', WfInstancia::ESTADO_EN_PROGRESO);

        return [
            'instancias'  => $instancias,
            'ciclos'      => $instancias->count(),
            'paso_actual' => $abierta?->pasoActual,
            'pendiente_de' => $abierta !== null
                ? $this->notifier->nombresIntervinientes($this->aprobadoresActuales($ficha))
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internos
    // ─────────────────────────────────────────────────────────────────────

    private function avanzar(FichFicha $ficha, int $usuarioId, string $comentario, string $accion): void
    {
        $instancia = $this->instanciaAbierta($ficha);

        if ($instancia === null) {
            Log::channel('daily')->notice('Fichas Técnicas: sin instancia de flujo abierta al '.$accion, [
                'id_ficha' => $ficha->id,
            ]);

            return;
        }

        try {
            $this->executor->aprobar((int) $instancia->id, $usuarioId, $comentario);
        } catch (\Throwable $e) {
            $this->registrarObservacionDeRespaldo(
                $instancia,
                $usuarioId,
                mb_strtoupper($accion).": {$comentario}",
                $e
            );
        }
    }

    private function instanciaAbierta(FichFicha $ficha): ?WfInstancia
    {
        if ($ficha->wf_instancia_id !== null) {
            $instancia = WfInstancia::query()->find($ficha->wf_instancia_id);

            if ($instancia !== null && $instancia->estaEnProgreso()) {
                return $instancia;
            }
        }

        $moduloId = $this->moduloId();

        if ($moduloId === null) {
            return null;
        }

        return WfInstancia::query()
            ->where('id_modulo', $moduloId)
            ->where('modulo_record_id', $ficha->id)
            ->enProgreso()
            ->latest('id')
            ->first();
    }

    private function cerrarInstanciaAbierta(FichFicha $ficha, int $usuarioId, string $motivo): void
    {
        $instancia = $this->instanciaAbierta($ficha);

        if ($instancia === null) {
            return;
        }

        try {
            $this->executor->cancelar((int) $instancia->id, $usuarioId, $motivo);
        } catch (\Throwable $e) {
            $this->registrarFallo('cerrarInstancia', $ficha, $e);
        }
    }

    /**
     * Contexto que el motor usa para resolver flujo y aprobadores.
     *
     * @return array<string, mixed>
     */
    private function contexto(FichFicha $ficha): array
    {
        return [
            'record_id'       => $ficha->id,
            'id_empresa'      => $ficha->id_empresa,
            'id_sucursal'     => $ficha->id_sucursal,
            'sucursal_legacy' => $ficha->sucursal_legacy,
            'id_agremiacion'  => $ficha->id_agremiacion,
            'id_especialidad' => $ficha->id_especialidad,
            'monto'           => (float) $ficha->vlr_contrato,
            'es_actualizacion' => $ficha->esActualizacion(),
            'version'         => $ficha->version,
            'consecutivo'     => $ficha->consecutivo,
        ];
    }

    private function moduloId(): ?int
    {
        $id = WfModulo::query()
            ->where('codigo', FichasTecnicasWorkflowSeeder::MODULO)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Cuando el motor rechaza la acción (aprobador no resuelto), se conserva la
     * traza como observación para no perder el registro de quién decidió qué.
     */
    private function registrarObservacionDeRespaldo(
        WfInstancia $instancia,
        int $usuarioId,
        string $comentario,
        \Throwable $causa,
    ): void {
        Log::channel('daily')->warning('Fichas Técnicas: el motor de flujos rechazó la acción', [
            'instancia_id' => $instancia->id,
            'usuario_id'   => $usuarioId,
            'error'        => $causa->getMessage(),
        ]);

        try {
            $this->executor->agregarObservacion((int) $instancia->id, $usuarioId, $comentario);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('Fichas Técnicas: tampoco se pudo registrar la observación', [
                'instancia_id' => $instancia->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function registrarFallo(string $operacion, FichFicha $ficha, \Throwable $e): void
    {
        Log::channel('daily')->warning("Fichas Técnicas: falló {$operacion} en el motor de flujos", [
            'id_ficha' => $ficha->id,
            'error'    => $e->getMessage(),
        ]);
    }
}
