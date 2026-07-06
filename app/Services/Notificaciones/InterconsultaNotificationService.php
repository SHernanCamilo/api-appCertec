<?php

namespace App\Services\Notificaciones;

use App\Models\Notificaciones\NotifEmailLog;
use App\Models\Notificaciones\NotifEmailTrace;
use App\Models\Notificaciones\NotifPlantilla;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class InterconsultaNotificationService
{
    private GraphFabricGatewayService $graphFabric;

    public function __construct(GraphFabricGatewayService $graphFabric)
    {
        $this->graphFabric = $graphFabric;
    }

    // =========================================================================
    // MÉTODO PRINCIPAL — Llamado por el Job
    // =========================================================================

    /**
     * Consulta interconsultas de hoy y envía emails pendientes.
     */
    public function checkAndSendEmails(): void
    {
        $startTime = now();
        Log::channel('notificaciones')->info('[INTERCONSULTAS] Verificación iniciada - ' . $startTime->format('Y-m-d H:i:s'));

        try {
            $interconsultas = $this->consultarInterconsultasHoy();

            if (empty($interconsultas)) {
                Log::channel('notificaciones')->info('[INTERCONSULTAS] No hay interconsultas para procesar');
                return;
            }

            Log::channel('notificaciones')->info('[INTERCONSULTAS] Interconsultas del día: ' . count($interconsultas));

            $enviados = 0;
            $omitidos = 0;
            $errores  = 0;

            foreach ($interconsultas as $item) {
                try {
                    $resultado = $this->procesarInterconsulta($item);
                    if ($resultado === 'ENVIADO') {
                        $enviados++;
                    } else {
                        $omitidos++;
                    }
                } catch (\Exception $e) {
                    $errores++;
                    Log::channel('notificaciones')->error('[INTERCONSULTAS] Error procesando ' . ($item['Identificacion'] ?? 'N/A') . ': ' . $e->getMessage());
                }
            }

            Log::channel('notificaciones')->info("[INTERCONSULTAS] Resultado: Enviados={$enviados} | Omitidos={$omitidos} | Errores={$errores}");
        } catch (\Exception $e) {
            Log::channel('notificaciones')->error('[INTERCONSULTAS] Error general: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // CONSULTA A FABRIC
    // =========================================================================

    /**
     * Consulta la vista VW_HC_NotificacionesInterconsultas desde Graph-Fabric.
     *
     * @return array<int, array>
     */
    public function consultarInterconsultasHoy(): array
    {
        $response = $this->graphFabric->queryAsSystem('ex', 'VW_HC_NotificacionesInterconsultas', [
            'columns' => [
                'Ingreso', 'Identificacion', 'Paciente', 'Clinica',
                'UnidadFuncional', 'Cama', 'Fecha_Orden', 'Orden',
                'Especialidad_Ordenada', 'DiagnosticoPpal', 'Folio',
                'EstadoOrden', 'Observaciones', 'Profesional', 'Email',
            ],
            'filters'  => [],
            'limit'    => 500,
            'offset'   => 0,
            'sort_col' => 'Fecha_Orden',
            'sort_dir' => 'desc',
        ]);

        if (!($response['success'] ?? false)) {
            Log::channel('notificaciones')->warning('[INTERCONSULTAS] Error consultando Fabric: ' . ($response['message'] ?? 'Sin respuesta'));
            return [];
        }

        // Filtrar registros sin email
        return collect($response['data'] ?? [])
            ->filter(fn ($item) => !empty(trim($item['Email'] ?? '')))
            ->values()
            ->all();
    }

    // =========================================================================
    // PROCESAMIENTO INDIVIDUAL
    // =========================================================================

    /**
     * Procesa una interconsulta y determina si debe enviar notificación.
     *
     * @return string 'ENVIADO' | 'OMITIDO'
     */
    public function procesarInterconsulta(array $item): string
    {
        $identificacion = trim($item['Identificacion'] ?? '');
        $profesional    = trim($item['Profesional'] ?? '');
        $fechaOrden     = $item['Fecha_Orden'] ?? null;
        $esAnulada      = strtoupper(trim($item['EstadoOrden'] ?? '')) === 'ANULADO';

        if (empty($identificacion) || empty($profesional)) {
            return 'OMITIDO';
        }

        // Formatear fecha para búsqueda en subject
        $fechaFormateada = $this->formatearFecha($fechaOrden);

        // Verificar si ya se envió o está programada SOLICITUD
        $yaTieneSolicitud = NotifEmailLog::where('tipo', 'INTERCONSULTA_SOLICITUD')
            ->where('identificacion_paciente', $identificacion)
            ->where('profesional_nombre', $profesional)
            ->where('subject', 'LIKE', "%{$fechaFormateada}%")
            ->whereIn('status', [NotifEmailLog::STATUS_SENT, NotifEmailLog::STATUS_PENDING])
            ->exists();

        // Verificar si ya se envió o está programada ANULACIÓN
        $yaTieneAnulacion = NotifEmailLog::where('tipo', 'INTERCONSULTA_ANULACION')
            ->where('identificacion_paciente', $identificacion)
            ->where('profesional_nombre', $profesional)
            ->where('subject', 'LIKE', "%{$fechaFormateada}%")
            ->whereIn('status', [NotifEmailLog::STATUS_SENT, NotifEmailLog::STATUS_PENDING])
            ->exists();

        // Determinar qué enviar
        if (!$esAnulada && !$yaTieneSolicitud) {
            return $this->enviarNotificacion($item, 'INTERCONSULTA_SOLICITUD');
        }

        if ($esAnulada && $yaTieneSolicitud && !$yaTieneAnulacion) {
            return $this->enviarNotificacion($item, 'INTERCONSULTA_ANULACION');
        }

        return 'OMITIDO';
    }

    // =========================================================================
    // ENVÍO DE EMAIL
    // =========================================================================

    /**
     * Envía la notificación por email y registra trazabilidad.
     *
     * @return string 'ENVIADO'
     * @throws \Exception si falla el envío
     */
    public function enviarNotificacion(array $item, string $tipo): string
    {
        $emailTo     = trim($item['Email']);
        $esAnulacion = $tipo === 'INTERCONSULTA_ANULACION';

        // Obtener plantilla HTML
        $htmlBody = $this->renderizarPlantilla($item, $esAnulacion);

        // Generar subject
        $fechaFormateada    = $this->formatearFecha($item['Fecha_Orden'] ?? null);
        $identificacion     = trim($item['Identificacion'] ?? '');

        $subject = $esAnulacion
            ? "⚠️ ANULACION de Interconsulta - Paciente: {$identificacion} - {$fechaFormateada}"
            : "Nueva Interconsulta - Paciente: {$identificacion} - {$fechaFormateada}";

        // Registrar en BD dentro de transacción
        return DB::transaction(function () use ($item, $tipo, $emailTo, $subject, $htmlBody) {
            $emailLog = NotifEmailLog::create([
                'tipo'                   => $tipo,
                'identificacion_paciente' => trim($item['Identificacion'] ?? ''),
                'nombre_paciente'        => $item['Paciente'] ?? null,
                'profesional_nombre'     => trim($item['Profesional'] ?? ''),
                'email_to'               => $emailTo,
                'subject'                => $subject,
                'body'                   => $htmlBody,
                'status'                 => NotifEmailLog::STATUS_PENDING,
                'delivery_status'        => NotifEmailLog::DELIVERY_PENDING,
                'intentos'               => 0,
                'fecha_envio'            => now(),
                'ingreso'                => $item['Ingreso'] ?? null,
                'clinica'                => $item['Clinica'] ?? null,
                'unidad_funcional'       => $item['UnidadFuncional'] ?? null,
                'cama'                   => $item['Cama'] ?? null,
                'orden'                  => $item['Orden'] ?? null,
                'especialidad'           => $item['Especialidad_Ordenada'] ?? null,
                'diagnostico'            => $item['DiagnosticoPpal'] ?? null,
                'folio'                  => $item['Folio'] ?? null,
                'estado_orden'           => $item['EstadoOrden'] ?? null,
                'fecha_orden'            => $this->parseFecha($item['Fecha_Orden'] ?? null),
                'observaciones'          => $item['Observaciones'] ?? null,
            ]);

            // Traza: PROGRAMADO
            $this->addTrace($emailLog->id, 'PROGRAMADO', 'PENDING', "Email programado para {$emailTo}");

            // Enviar email
            try {
                Mail::html($htmlBody, function ($message) use ($emailTo, $subject) {
                    $message->to($emailTo)
                            ->subject($subject);
                });

                // Obtener message-id si está disponible
                $messageId = null;

                // Actualizar log como enviado
                $emailLog->update([
                    'status'        => NotifEmailLog::STATUS_SENT,
                    'message_id'    => $messageId,
                    'fecha_intento' => now(),
                    'intentos'      => $emailLog->intentos + 1,
                ]);

                // Traza: ENVIADO
                $this->addTrace($emailLog->id, 'ENVIADO', 'SUCCESS', "Email enviado exitosamente a {$emailTo}");

                return 'ENVIADO';
            } catch (\Exception $e) {
                // Actualizar log como error
                $emailLog->update([
                    'status'          => NotifEmailLog::STATUS_ERROR,
                    'delivery_status' => NotifEmailLog::DELIVERY_FAILED,
                    'error_message'   => $e->getMessage(),
                    'fecha_intento'   => now(),
                    'intentos'        => $emailLog->intentos + 1,
                ]);

                // Traza: ERROR
                $this->addTrace($emailLog->id, 'ERROR', 'ERROR', "Error enviando email: {$e->getMessage()}");

                throw $e;
            }
        });
    }

    // =========================================================================
    // VERIFICACIÓN DE DELIVERY
    // =========================================================================

    /**
     * Verifica si un email fue entregado.
     * Si pasaron más de 5 min sin rebote → marcar como DELIVERED.
     */
    public function verificarDelivery(int $emailLogId): void
    {
        $emailLog = NotifEmailLog::find($emailLogId);

        if (!$emailLog) {
            return;
        }

        if ($emailLog->status !== NotifEmailLog::STATUS_SENT) {
            return;
        }

        if ($emailLog->delivery_status !== NotifEmailLog::DELIVERY_PENDING) {
            return;
        }

        // Si pasaron más de 5 minutos sin rebote → considerar entregado
        if ($emailLog->fecha_intento && $emailLog->fecha_intento->diffInMinutes(now()) >= 5) {
            $emailLog->update([
                'delivery_status' => NotifEmailLog::DELIVERY_DELIVERED,
                'delivered_at'    => now(),
            ]);

            $this->addTrace($emailLogId, 'ENTREGADO', 'SUCCESS', 'Email considerado entregado (sin rebote en 5+ min)');
        }
    }

    // =========================================================================
    // RENDERIZADO DE PLANTILLA
    // =========================================================================

    /**
     * Renderiza el HTML del email usando la plantilla de BD o un fallback.
     */
    private function renderizarPlantilla(array $item, bool $esAnulacion): string
    {
        $codigoPlantilla = $esAnulacion ? 'INTERCONSULTA_ANULACION' : 'INTERCONSULTA_SOLICITUD';

        $plantilla = NotifPlantilla::activas()->porCodigo($codigoPlantilla)->first();

        // Generar bloque HTML de observaciones si existen
        $observaciones    = $item['Observaciones'] ?? '';
        $observacionesHtml = '';
        if (!empty(trim($observaciones))) {
            $observacionesHtml = '<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px; margin: 15px 0;"><strong>📝 OBSERVACIONES:</strong><br>' . e($observaciones) . '</div>';
        }

        $variables = [
            'profesional'       => $item['Profesional'] ?? 'N/A',
            'paciente'          => $item['Paciente'] ?? 'N/A',
            'identificacion'    => $item['Identificacion'] ?? 'N/A',
            'clinica'           => $item['Clinica'] ?? 'N/A',
            'unidad_funcional'  => $item['UnidadFuncional'] ?? 'N/A',
            'cama'              => $item['Cama'] ?? 'No especificada',
            'orden'             => $item['Orden'] ?? 'N/A',
            'especialidad'      => $item['Especialidad_Ordenada'] ?? 'Interconsulta',
            'diagnostico'       => $item['DiagnosticoPpal'] ?? 'N/A',
            'fecha_orden'       => $this->formatearFecha($item['Fecha_Orden'] ?? null),
            'folio'             => $item['Folio'] ?? 'N/A',
            'estado_orden'      => $item['EstadoOrden'] ?? 'N/A',
            'observaciones'     => $observaciones,
            'observaciones_html' => $observacionesHtml,
            'fecha_generado'    => now()->timezone('America/Bogota')->format('d/m/Y, H:i:s'),
        ];

        if ($plantilla) {
            return $plantilla->renderizar($variables);
        }

        // Fallback: generar HTML inline (no debería pasar si el seeder corrió)
        Log::channel('notificaciones')->warning("[INTERCONSULTAS] Plantilla '{$codigoPlantilla}' no encontrada, usando fallback.");
        return $this->generarHtmlFallback($variables, $esAnulacion);
    }

    /**
     * HTML fallback en caso de que no exista la plantilla en BD.
     */
    private function generarHtmlFallback(array $vars, bool $esAnulacion): string
    {
        $borderColor = $esAnulacion ? '#dc3545' : '#0d6efd';
        $titulo      = $esAnulacion ? '⚠️ INTERCONSULTA ANULADA' : '✅ NUEVA INTERCONSULTA';

        return "<html><body><h2 style='color:{$borderColor}'>{$titulo}</h2>"
             . "<p>Paciente: {$vars['paciente']} ({$vars['identificacion']})</p>"
             . "<p>Profesional: {$vars['profesional']}</p>"
             . "<p>Especialidad: {$vars['especialidad']}</p>"
             . "<p>Fecha: {$vars['fecha_orden']}</p>"
             . "</body></html>";
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Formatea una fecha al estilo dd/mm/yyyy, HH:mm:ss.
     */
    private function formatearFecha(?string $fecha): string
    {
        if (!$fecha) {
            return 'N/A';
        }

        try {
            return Carbon::parse($fecha)->format('d/m/Y, H:i:s');
        } catch (\Exception $e) {
            return $fecha;
        }
    }

    /**
     * Parsea fecha a Carbon para guardar en BD.
     */
    private function parseFecha(?string $fecha): ?Carbon
    {
        if (!$fecha) {
            return null;
        }

        try {
            return Carbon::parse($fecha);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Registra un evento de trazabilidad.
     */
    private function addTrace(int $emailLogId, string $eventType, string $eventStatus, string $message, ?string $details = null): void
    {
        NotifEmailTrace::create([
            'email_log_id'  => $emailLogId,
            'event_type'    => $eventType,
            'event_status'  => $eventStatus,
            'event_message' => $message,
            'event_details' => $details,
            'created_at'    => now(),
        ]);
    }
}