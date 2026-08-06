<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Models\Notificaciones\NotifEmailLog;
use App\Models\Notificaciones\NotifPlantilla;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notificaciones por correo en los cambios de estado de una ficha.
 *
 * Reemplaza los cuatro `sendemail.php` duplicados del legacy (uno por módulo),
 * que además llevaban las credenciales SMTP de Gmail escritas en el código
 * fuente. Aquí se usa el mailer configurado en `.env` y se reutiliza la
 * infraestructura existente de `notif_plantillas` / `notif_email_logs` para
 * plantilla y trazabilidad.
 */
final class FichNotificacionService
{
    /** Código de plantilla en `notif_plantillas`. */
    private const PLANTILLA = 'FICHA_TECNICA_CAMBIO_ESTADO';

    private const TIPO_LOG = 'FICHA_TECNICA_ESTADO';

    /**
     * Envía la notificación correspondiente al nuevo estado de la ficha.
     *
     * @return list<string> Correos a los que se envió
     */
    public function notificarCambioEstado(FichFicha $ficha, EstadoFicha $estado, string $observacion = ''): array
    {
        $ficha->loadMissing([
            'agremiacion:id,nombre',
            'especialidad:id,descripcion',
            'empresa:id,nombre',
            'generador:id,name,email',
            'autorizador:id,name,email',
            'aprobador:id,name,email',
        ]);

        $destinatarios = $this->destinatarios($ficha, $estado);

        if ($destinatarios === []) {
            Log::channel('daily')->warning('Fichas Técnicas: cambio de estado sin destinatarios de correo', [
                'id_ficha' => $ficha->id,
                'estado'   => $estado->value,
            ]);

            return [];
        }

        $asunto = $this->asunto($ficha, $estado);
        $html   = $this->renderizar($ficha, $estado, $observacion);

        $enviados = [];

        foreach ($destinatarios as $correo) {
            if ($this->enviar($ficha, $estado, $correo, $asunto, $html)) {
                $enviados[] = $correo;
            }
        }

        return $enviados;
    }

    /**
     * Destinatarios según el punto del flujo.
     *
     *  - Enviada a autorización  → Dirección Médica
     *  - Autorizada              → Vicepresidencia Financiera
     *  - Aprobada / vigente      → generador + autorizador
     *  - Corrección requerida    → generador
     *
     * @return list<string>
     */
    private function destinatarios(FichFicha $ficha, EstadoFicha $estado): array
    {
        $roles = config('fichas_tecnicas.roles');

        $correos = match (true) {
            // Devolución para corrección: vuelve al generador.
            in_array($estado, EstadoFicha::correccionRequerida(), true) => [
                $ficha->generador?->email,
            ],

            // Entra al flujo: avisa a la Dirección Médica.
            in_array($estado, EstadoFicha::pendientesAutorizacion(), true) => $this->correosDeRol(
                $roles['autorizador']
            ),

            // Autorizada: avisa a la Vicepresidencia Financiera.
            in_array($estado, EstadoFicha::pendientesRevisionFinanciera(), true) => [
                $ficha->aprobador?->email,
                ...$this->correosDeRol($roles['aprobador']),
            ],

            // Formalizada: confirma a quienes participaron.
            in_array($estado, EstadoFicha::finalizadas(), true) => [
                $ficha->generador?->email,
                $ficha->autorizador?->email,
            ],

            // Cancelación: informa al generador.
            in_array($estado, EstadoFicha::canceladas(), true) => [
                $ficha->generador?->email,
            ],

            default => [],
        };

        return array_values(array_unique(array_filter(
            $correos,
            static fn (?string $c): bool => is_string($c) && filter_var($c, FILTER_VALIDATE_EMAIL) !== false
        )));
    }

    /**
     * Correos de los usuarios con un rol Spatie determinado.
     *
     * En el legacy los destinatarios llegaban como campos ocultos del
     * formulario (`$_POST['aprobador']`), lo que permitía manipularlos
     * desde el navegador.
     *
     * @return list<string>
     */
    private function correosDeRol(string $rol): array
    {
        try {
            /** @var list<string> $correos */
            $correos = \App\Models\User::query()
                ->where('estado', true)
                ->whereHas('roles', fn ($q) => $q->where('name', $rol))
                ->pluck('email')
                ->all();

            return $correos;
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('Fichas Técnicas: no se pudieron resolver correos por rol', [
                'rol'   => $rol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function asunto(FichFicha $ficha, EstadoFicha $estado): string
    {
        $referencia = $ficha->consecutivo !== null && $ficha->consecutivo !== ''
            ? $ficha->consecutivo
            : "borrador No. {$ficha->id}";

        return match (true) {
            in_array($estado, EstadoFicha::correccionRequerida(), true)
                => "Ficha Técnica devuelta para corrección — {$referencia}",

            in_array($estado, EstadoFicha::pendientesAutorizacion(), true)
                => "Ficha Técnica pendiente de su autorización — {$referencia}",

            in_array($estado, EstadoFicha::pendientesRevisionFinanciera(), true)
                => "Ficha Técnica autorizada, pendiente de revisión financiera — {$referencia}",

            in_array($estado, EstadoFicha::aprobadas(), true)
                => "Ficha Técnica aprobada — {$referencia}",

            in_array($estado, EstadoFicha::vigentesEstados(), true)
                => "Ficha Técnica vigente — {$referencia}",

            in_array($estado, EstadoFicha::canceladas(), true)
                => "Ficha Técnica cancelada — {$referencia}",

            default => "Ficha Técnica: cambio de estado — {$referencia}",
        };
    }

    private function renderizar(FichFicha $ficha, EstadoFicha $estado, string $observacion): string
    {
        $variables = [
            'consecutivo'  => $ficha->consecutivo ?? "Borrador No. {$ficha->id}",
            'estado'       => $estado->label(),
            'agremiacion'  => $ficha->agremiacion?->nombre ?? 'N/A',
            'especialidad' => $ficha->especialidad?->descripcion ?? 'N/A',
            'empresa'      => $ficha->empresa?->nombre ?? 'N/A',
            'valor'        => '$'.number_format((float) $ficha->vlr_contrato, 2, ',', '.'),
            'fecha_ini'    => $ficha->fecha_ini?->format('d/m/Y') ?? 'N/A',
            'fecha_fin'    => $ficha->fecha_fin?->format('d/m/Y') ?? 'N/A',
            'generador'    => $ficha->generador?->name ?? 'N/A',
            'observacion'  => $observacion !== '' ? $observacion : 'Sin observaciones',
            'fecha_evento' => now()->timezone('America/Bogota')->format('d/m/Y H:i'),
        ];

        $plantilla = NotifPlantilla::query()->activas()->porCodigo(self::PLANTILLA)->first();

        if ($plantilla !== null) {
            return $plantilla->renderizar($variables);
        }

        return $this->htmlFallback($variables, $estado);
    }

    /**
     * @param  array<string, string>  $v
     */
    private function htmlFallback(array $v, EstadoFicha $estado): string
    {
        $color  = $estado->colorHex();
        $titulo = e($v['estado']);

        $filas = '';
        foreach ([
            'Consecutivo'  => $v['consecutivo'],
            'Agremiación'  => $v['agremiacion'],
            'Especialidad' => $v['especialidad'],
            'Empresa'      => $v['empresa'],
            'Valor'        => $v['valor'],
            'Vigencia'     => $v['fecha_ini'].' — '.$v['fecha_fin'],
            'Generador'    => $v['generador'],
        ] as $etiqueta => $valor) {
            $filas .= '<tr>'
                .'<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;">'.e($etiqueta).'</td>'
                .'<td style="padding:8px 12px;border-bottom:1px solid #e9ecef;color:#212529;">'.e($valor).'</td>'
                .'</tr>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"><title>{$titulo}</title></head>
        <body style="margin:0;padding:24px;background:#f8f9fa;font-family:Segoe UI,Arial,sans-serif;">
          <table role="presentation" style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #dee2e6;">
            <tr>
              <td style="background:{$color};color:#fff;padding:18px 24px;">
                <h2 style="margin:0;font-size:18px;">Ficha Técnica — {$titulo}</h2>
              </td>
            </tr>
            <tr><td style="padding:20px 24px;">
              <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;">{$filas}</table>
              <div style="margin-top:18px;padding:12px;background:#fff3cd;border:1px solid #ffe69c;border-radius:8px;font-size:14px;">
                <strong>Observaciones:</strong><br>{$v['observacion']}
              </div>
              <p style="margin-top:20px;font-size:13px;color:#6c757d;">
                Ingrese a JadeOne para revisar el detalle de la ficha.
              </p>
            </td></tr>
            <tr><td style="background:#f1f3f5;padding:12px 24px;font-size:12px;color:#6c757d;">
              JadeOne · Fichas Técnicas Médicas · {$v['fecha_evento']}
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    private function enviar(
        FichFicha $ficha,
        EstadoFicha $estado,
        string $correo,
        string $asunto,
        string $html,
    ): bool {
        $log = NotifEmailLog::query()->create([
            'tipo'            => self::TIPO_LOG,
            'email_to'        => $correo,
            'subject'         => $asunto,
            'body'            => $html,
            'status'          => NotifEmailLog::STATUS_PENDING,
            'delivery_status' => NotifEmailLog::DELIVERY_PENDING,
            'intentos'        => 0,
            'fecha_envio'     => now(),
            'orden'           => (string) $ficha->id,
            'especialidad'    => $ficha->especialidad?->descripcion,
            'estado_orden'    => $estado->label(),
            'folio'           => $ficha->consecutivo,
        ]);

        try {
            Mail::html($html, static function ($message) use ($correo, $asunto): void {
                $message->to($correo)->subject($asunto);
            });

            $log->update([
                'status'        => NotifEmailLog::STATUS_SENT,
                'fecha_intento' => now(),
                'intentos'      => $log->intentos + 1,
            ]);

            return true;
        } catch (\Throwable $e) {
            $log->update([
                'status'          => NotifEmailLog::STATUS_ERROR,
                'delivery_status' => NotifEmailLog::DELIVERY_FAILED,
                'error_message'   => $e->getMessage(),
                'fecha_intento'   => now(),
                'intentos'        => $log->intentos + 1,
            ]);

            Log::channel('daily')->error('Fichas Técnicas: error enviando correo', [
                'id_ficha' => $ficha->id,
                'email_to' => $correo,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }
}
