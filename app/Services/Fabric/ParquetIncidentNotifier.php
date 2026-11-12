<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use App\Models\BiParquetIncident;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisa al equipo de TI que un parquet lleva demasiado tiempo generándose.
 *
 * Siempre deja constancia en el log (`[ParquetLento]`). Si está definido
 * `FABRIC_ALERT_EMAIL`, además envía un correo. Un fallo al notificar nunca
 * debe romper el flujo del usuario ni el cron: se registra y se sigue.
 */
final class ParquetIncidentNotifier
{
    public function notify(BiParquetIncident $incident): void
    {
        $limite  = (int) config('fabric.slow_threshold_s', 300);
        $origen  = $incident->source === BiParquetIncident::SOURCE_USER
            ? 'un usuario pidio actualizarla' . ($incident->user_name ? " ({$incident->user_name})" : '')
            : 'la detecto el vigilante de Graph-Fabric';

        if ($incident->reason === BiParquetIncident::REASON_ERROR) {
            $mensaje = sprintf(
                'La actualizacion de %s.%s FALLO tras %d s; %s. Motivo: %s',
                $incident->schema_name,
                $incident->view_name,
                $incident->elapsed_s,
                $origen,
                $incident->detail ?: 'sin detalle'
            );
        } else {
            $mensaje = sprintf(
                'La vista %s.%s lleva %d s generandose (limite %d s); %s.%s',
                $incident->schema_name,
                $incident->view_name,
                $incident->elapsed_s,
                $limite,
                $origen,
                $incident->stage ? " Fase: {$incident->stage}." : ''
            );
        }

        Log::warning('[ParquetLento] ' . $mensaje, ['incident_id' => $incident->id]);

        $to = trim((string) config('fabric.alert_email', ''));
        if ($to !== '') {
            try {
                Mail::raw($mensaje, function ($m) use ($to, $incident): void {
                    $m->to($to)->subject("Parquet lento: {$incident->schema_name}.{$incident->view_name}");
                });
            } catch (\Throwable $e) {
                Log::error('[ParquetLento] No se pudo enviar el correo de alerta', ['error' => $e->getMessage()]);
            }
        }

        $incident->forceFill(['notified_at' => now()])->save();
    }
}
