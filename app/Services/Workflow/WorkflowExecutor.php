<?php

namespace App\Services\Workflow;

use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfAprobacion;
use App\Models\Workflow\WfPaso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ejecuta flujos de aprobación.
 *
 * Responsabilidades:
 *   - Iniciar instancias de flujo
 *   - Avanzar al siguiente paso
 *   - Registrar aprobaciones/rechazos
 *   - Completar o rechazar flujos
 */
class WorkflowExecutor
{
    protected WorkflowNotifier $notifier;

    public function __construct(WorkflowNotifier $notifier)
    {
        $this->notifier = $notifier;
    }

    /**
     * Inicia una nueva instancia de flujo.
     *
     * @param WfDefinicion $flujo Flujo a ejecutar
     * @param int $solicitanteId ID del usuario solicitante
     * @param array $contexto Contexto adicional de la solicitud
     * @param string|null $consecutivo Número consecutivo de la solicitud
     *
     * @return WfInstancia
     */
    public function iniciarFlujo(
        WfDefinicion $flujo,
        int $solicitanteId,
        array $contexto = [],
        ?string $consecutivo = null
    ): WfInstancia {
        return DB::transaction(function () use ($flujo, $solicitanteId, $contexto, $consecutivo) {
            // Obtener el primer paso del flujo
            $primerPaso = $flujo->pasos()->activos()->ordenados()->first();

            if (!$primerPaso) {
                throw new \Exception("El flujo '{$flujo->codigo}' no tiene pasos configurados");
            }

            // Obtener record_id del contexto
            $recordId = $contexto['record_id'] ?? null;
            if (!$recordId) {
                throw new \Exception("El contexto debe incluir 'record_id'");
            }

            // Crear instancia
            $instancia = WfInstancia::create([
                'id_definicion' => $flujo->id,
                'id_modulo' => $flujo->id_modulo,
                'modulo_record_id' => $recordId,
                'solicitante_id' => $solicitanteId,
                'contexto' => $contexto,
                'consecutivo' => $consecutivo,
                'id_paso_actual' => $primerPaso->id,
                'estado' => WfInstancia::ESTADO_EN_PROGRESO,
            ]);

            Log::info("Flujo iniciado", [
                'instancia_id' => $instancia->id,
                'flujo' => $flujo->codigo,
                'modulo_id' => $flujo->id_modulo,
                'record_id' => $recordId,
                'solicitante_id' => $solicitanteId,
                'paso_inicial' => $primerPaso->nombre_paso,
            ]);

            // Notificar a los aprobadores del primer paso
            $instanciaCargada = $instancia->load(['pasoActual', 'definicion', 'solicitante']);
            $this->notifier->notificarAprobador($instanciaCargada);

            return $instanciaCargada;
        });
    }

    /**
     * Registra una aprobación y avanza al siguiente paso.
     *
     * @param int $instanciaId ID de la instancia
     * @param int $userId Usuario que aprueba
     * @param string|null $comentario Comentario opcional
     * @param float|null $montoAutorizado Monto autorizado (si aplica)
     *
     * @return WfInstancia
     */
    public function aprobar(
        int $instanciaId,
        int $userId,
        ?string $comentario = null,
        ?float $montoAutorizado = null
    ): WfInstancia {
        return DB::transaction(function () use ($instanciaId, $userId, $comentario, $montoAutorizado) {
            $instancia = WfInstancia::with(['pasoActual', 'definicion', 'solicitante'])->findOrFail($instanciaId);

            if (!$instancia->estaEnProgreso()) {
                throw new \Exception("La instancia no está en progreso");
            }

            // Validar que el usuario esté autorizado para aprobar este paso
            if (!$this->notifier->esUsuarioAutorizado($userId, $instancia)) {
                throw new \Exception("No estás autorizado para aprobar este paso");
            }

            // Registrar aprobación
            WfAprobacion::create([
                'id_instancia' => $instancia->id,
                'id_paso' => $instancia->id_paso_actual,
                'id_user' => $userId,
                'accion' => WfAprobacion::ACCION_APROBADO,
                'comentario' => $comentario,
                'monto_autorizado' => $montoAutorizado,
                'fecha_accion' => now(),
            ]);

            // Buscar siguiente paso
            $siguientePaso = $this->obtenerSiguientePaso($instancia);

            if ($siguientePaso) {
                // Avanzar al siguiente paso
                $instancia->update([
                    'id_paso_actual' => $siguientePaso->id,
                ]);

                Log::info("Flujo avanzado", [
                    'instancia_id' => $instancia->id,
                    'paso_anterior' => $instancia->pasoActual->nombre_paso,
                    'paso_actual' => $siguientePaso->nombre_paso,
                    'aprobado_por' => $userId,
                ]);

                // Notificar a los aprobadores del nuevo paso y al solicitante
                $instanciaActualizada = $instancia->fresh(['pasoActual']);
                $this->notifier->notificarAprobador($instanciaActualizada);
                if ($instancia->solicitante_id) {
                    $this->notifier->notificarAprobacion($instanciaActualizada, $instancia->solicitante_id);
                }
            } else {
                // No hay más pasos, completar flujo
                $instancia->update([
                    'estado' => WfInstancia::ESTADO_COMPLETADO,
                    'id_paso_actual' => null,
                    'fecha_completado' => now(),
                ]);

                Log::info("Flujo completado", [
                    'instancia_id' => $instancia->id,
                    'aprobado_por' => $userId,
                ]);

                // Notificar al solicitante que el flujo se completó
                if ($instancia->solicitante_id) {
                    $this->notifier->notificarAprobacion($instancia->fresh(), $instancia->solicitante_id);
                }
            }

            return $instancia->fresh(['pasoActual', 'definicion']);
        });
    }

    /**
     * Registra un rechazo y finaliza el flujo.
     *
     * @param int $instanciaId ID de la instancia
     * @param int $userId Usuario que rechaza
     * @param string $comentario Motivo del rechazo
     *
     * @return WfInstancia
     */
    public function rechazar(int $instanciaId, int $userId, string $comentario): WfInstancia
    {
        return DB::transaction(function () use ($instanciaId, $userId, $comentario) {
            $instancia = WfInstancia::with(['pasoActual', 'solicitante'])->findOrFail($instanciaId);

            if (!$instancia->estaEnProgreso()) {
                throw new \Exception("La instancia no está en progreso");
            }

            // Validar que el usuario esté autorizado para aprobar este paso
            if (!$this->notifier->esUsuarioAutorizado($userId, $instancia)) {
                throw new \Exception("No estás autorizado para rechazar este paso");
            }

            // Registrar rechazo
            WfAprobacion::create([
                'id_instancia' => $instancia->id,
                'id_paso' => $instancia->id_paso_actual,
                'id_user' => $userId,
                'accion' => WfAprobacion::ACCION_RECHAZADO,
                'comentario' => $comentario,
                'fecha_accion' => now(),
            ]);

            // Finalizar flujo como rechazado
            $instancia->update([
                'estado' => WfInstancia::ESTADO_RECHAZADO,
                'fecha_rechazado' => now(),
            ]);

            Log::info("Flujo rechazado", [
                'instancia_id' => $instancia->id,
                'paso' => $instancia->pasoActual->nombre_paso,
                'rechazado_por' => $userId,
                'motivo' => $comentario,
            ]);

            // Notificar al solicitante que el flujo fue rechazado
            if ($instancia->solicitante_id) {
                $this->notifier->notificarRechazo($instancia->fresh(), $instancia->solicitante_id, $comentario);
            }

            return $instancia->fresh(['pasoActual', 'definicion']);
        });
    }

    /**
     * Registra una observación sin avanzar el flujo.
     */
    public function agregarObservacion(int $instanciaId, int $userId, string $comentario): WfAprobacion
    {
        $instancia = WfInstancia::findOrFail($instanciaId);

        return WfAprobacion::create([
            'id_instancia' => $instancia->id,
            'id_paso' => $instancia->id_paso_actual,
            'id_user' => $userId,
            'accion' => WfAprobacion::ACCION_OBSERVACION,
            'comentario' => $comentario,
            'fecha_accion' => now(),
        ]);
    }

    /**
     * Cancela una instancia de flujo.
     */
    public function cancelar(int $instanciaId, int $userId, string $motivo): WfInstancia
    {
        return DB::transaction(function () use ($instanciaId, $userId, $motivo) {
            $instancia = WfInstancia::findOrFail($instanciaId);

            $instancia->update([
                'estado' => WfInstancia::ESTADO_CANCELADO,
            ]);

            // Registrar como observación
            WfAprobacion::create([
                'id_instancia' => $instancia->id,
                'id_paso' => $instancia->id_paso_actual,
                'id_user' => $userId,
                'accion' => WfAprobacion::ACCION_OBSERVACION,
                'comentario' => "CANCELADO: {$motivo}",
                'fecha_accion' => now(),
            ]);

            Log::info("Flujo cancelado", [
                'instancia_id' => $instancia->id,
                'cancelado_por' => $userId,
                'motivo' => $motivo,
            ]);

            return $instancia->fresh();
        });
    }

    /**
     * Obtiene el siguiente paso del flujo.
     */
    private function obtenerSiguientePaso(WfInstancia $instancia): ?WfPaso
    {
        $pasoActual = $instancia->pasoActual;
        $contexto = $instancia->contexto ?? [];
        
        $pasos = WfPaso::where('id_definicion', $instancia->id_definicion)
            ->where('orden', '>', $pasoActual->orden)
            ->activos()
            ->ordenados()
            ->get();

        // Buscar el primer paso que cumpla con las reglas (si tiene)
        foreach ($pasos as $paso) {
            if (empty($paso->reglas) || $paso->evaluarReglas($contexto)) {
                return $paso;
            }
        }

        return null;
    }

    /**
     * Obtiene el historial completo de una instancia.
     */
    public function obtenerHistorial(int $instanciaId): array
    {
        $instancia = WfInstancia::with([
            'aprobaciones.user',
            'aprobaciones.paso',
            'definicion',
            'pasoActual',
            'solicitante',
        ])->findOrFail($instanciaId);

        return [
            'instancia' => $instancia,
            'aprobaciones' => $instancia->aprobaciones,
        ];
    }
}
