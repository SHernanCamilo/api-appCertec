<?php

namespace App\Services\Workflow;

use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfNotificacion;
use App\Models\Workflow\WfAprobador;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Gestiona notificaciones del motor de flujos.
 *
 * Responsabilidades:
 *   - Notificar a aprobadores cuando tienen solicitudes pendientes
 *   - Notificar al solicitante sobre cambios de estado
 *   - Resolver quién debe aprobar cada paso
 */
class WorkflowNotifier
{
    /**
     * Notifica al aprobador del paso actual.
     *
     * @param WfInstancia $instancia
     * @return void
     */
    public function notificarAprobador(WfInstancia $instancia): void
    {
        if (!$instancia->estaEnProgreso()) {
            return;
        }

        $aprobadores = $this->resolverAprobadores($instancia);

        foreach ($aprobadores as $aprobador) {
            $this->crearNotificacion(
                $instancia,
                $aprobador->id,
                WfNotificacion::TIPO_PENDIENTE_APROBACION,
                "Tienes una solicitud pendiente de aprobación en el paso: {$instancia->pasoActual->nombre_paso}"
            );
        }

        Log::info("Notificaciones enviadas", [
            'instancia_id' => $instancia->id,
            'paso' => $instancia->pasoActual->nombre_paso,
            'aprobadores' => $aprobadores->pluck('id')->toArray(),
        ]);
    }

    /**
     * Notifica al solicitante sobre aprobación.
     */
    public function notificarAprobacion(WfInstancia $instancia, int $solicitanteId): void
    {
        $mensaje = $instancia->estaCompletado()
            ? "Tu solicitud ha sido aprobada completamente"
            : "Tu solicitud avanzó al paso: {$instancia->pasoActual->nombre_paso}";

        $this->crearNotificacion(
            $instancia,
            $solicitanteId,
            WfNotificacion::TIPO_APROBADO,
            $mensaje
        );
    }

    /**
     * Notifica al solicitante sobre rechazo.
     */
    public function notificarRechazo(WfInstancia $instancia, int $solicitanteId, string $motivo): void
    {
        $this->crearNotificacion(
            $instancia,
            $solicitanteId,
            WfNotificacion::TIPO_RECHAZADO,
            "Tu solicitud fue rechazada en el paso: {$instancia->pasoActual->nombre_paso}. Motivo: {$motivo}"
        );
    }

    /**
     * Resuelve quiénes deben aprobar el paso actual.
     *
     * Estrategias:
     *   1. Aprobador fijo (id_user)
     *   2. Aprobador por unidad funcional
     *   3. Aprobador por prefijo de sucursal
     *
     * @param WfInstancia $instancia
     * @return \Illuminate\Support\Collection
     */
    public function resolverAprobadores(WfInstancia $instancia): \Illuminate\Support\Collection
    {
        $paso = $instancia->pasoActual;
        
        // Obtener configuración de aprobadores para este paso
        $aprobadores = WfAprobador::where('id_paso', $paso->id)
            ->activos()
            ->titulares()
            ->with('user')
            ->get();

        if ($aprobadores->isEmpty()) {
            Log::warning("No hay aprobadores configurados para el paso", [
                'paso_id' => $paso->id,
                'paso_nombre' => $paso->nombre_paso,
            ]);
            return collect();
        }

        $usuarios = collect();

        foreach ($aprobadores as $aprobador) {
            // Estrategia 1: Aprobador fijo
            if ($aprobador->id_user) {
                $usuarios->push($aprobador->user);
                continue;
            }

            // Estrategia 2: Por unidad funcional (requiere contexto del registro)
            if ($aprobador->id_unidad_funcional) {
                $usuariosUF = $this->obtenerAprobadoresPorUnidadFuncional(
                    $aprobador->id_unidad_funcional,
                    $paso->rol_aprobador
                );
                $usuarios = $usuarios->merge($usuariosUF);
                continue;
            }

            // Estrategia 3: Por prefijo de sucursal (requiere contexto del registro)
            if ($aprobador->prefijo_sucursal) {
                // TODO: Implementar lógica de resolución por prefijo
                // Requiere acceder al registro del módulo para obtener la sucursal
            }
        }

        return $usuarios->unique('id');
    }

    /**
     * Obtiene aprobadores de una unidad funcional por rol.
     */
    private function obtenerAprobadoresPorUnidadFuncional(int $idUnidadFuncional, string $rol): \Illuminate\Support\Collection
    {
        return \App\Models\Finance\AntiAprobador::where('id_unidad_funcional', $idUnidadFuncional)
            ->where('rol_aprobador', $rol)
            ->where('es_suplente', false)
            ->activos()
            ->with('user')
            ->get()
            ->pluck('user');
    }

    /**
     * Crea una notificación en la base de datos.
     */
    private function crearNotificacion(
        WfInstancia $instancia,
        int $userId,
        string $tipo,
        string $mensaje
    ): WfNotificacion {
        return WfNotificacion::create([
            'id_instancia' => $instancia->id,
            'id_user' => $userId,
            'tipo' => $tipo,
            'mensaje' => $mensaje,
            'leida' => false,
        ]);
    }

    /**
     * Marca todas las notificaciones de un usuario como leídas.
     */
    public function marcarTodasComoLeidas(int $userId): int
    {
        return WfNotificacion::porUsuario($userId)
            ->noLeidas()
            ->update([
                'leida' => true,
                'fecha_lectura' => now(),
            ]);
    }

    /**
     * Obtiene notificaciones pendientes de un usuario.
     */
    public function obtenerNotificacionesPendientes(int $userId, int $limit = 50): \Illuminate\Support\Collection
    {
        return WfNotificacion::porUsuario($userId)
            ->noLeidas()
            ->with('instancia.definicion')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
