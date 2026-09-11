<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Notificaciones\NotifEmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostica por qué la deteccion de rebotes de notificaciones no encuentra
 * rebotes reales aunque sí llegan al buzon de Outlook.
 *
 * Comprueba TRES cosas, sin modificar nada:
 *   1. Token de Microsoft Graph: ¿el client_secret de Azure sigue vivo?
 *   2. Pool de candidatos: ¿cuantos emails quedan en SENT+PENDING para revisar?
 *      (si es 0, "Verificar Rebotes" siempre dara 0 revisados).
 *   3. Buzon real: ¿cuantos NDR (rebotes) hay HOY en la bandeja del sender que
 *      el sistema NO esta capturando?
 *
 * USO:
 *   php artisan notif:diagnose-bounces
 *   php artisan notif:diagnose-bounces --days=7
 */
final class DiagnoseBouncesCommand extends Command
{
    protected $signature = 'notif:diagnose-bounces
        {--days=3 : Cuantos dias hacia atras inspeccionar el buzon}
        {--sweep : Ademas de diagnosticar, EJECUTA el barrido y marca los rebotes encontrados}';

    protected $description = 'Diagnostica la deteccion de rebotes de notificaciones (token Graph, pool de candidatos y NDR en el buzon)';

    public function handle(): int
    {
        $tenantId     = env('EMAIL_AZURE_TENANT_ID', '');
        $clientId     = env('EMAIL_AZURE_CLIENT_ID', '');
        $clientSecret = env('EMAIL_AZURE_CLIENT_SECRET', '');
        $senderEmail  = env('MICROSOFT_EMAIL', 'notificaciones.apps@medilaser.com.co');
        $enabled      = env('USE_MICROSOFT_GRAPH', 'false');

        $this->line('Sender  : ' . $senderEmail);
        $this->line('Graph on: ' . var_export($enabled, true));
        $this->newLine();

        // ── 1. Token de Graph ────────────────────────────────────────────────
        $this->info('[1/3] Probando el token de Microsoft Graph (client_credentials)...');

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            $this->error('  Faltan credenciales EMAIL_AZURE_* en el .env.');
            return self::FAILURE;
        }

        $tokenResp = Http::asForm()->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
                'grant_type'    => 'client_credentials',
            ]
        );

        if ($tokenResp->failed()) {
            $this->error('  >> El token FALLO. Respuesta de Azure:');
            $this->line('     ' . $tokenResp->body());
            $err = (string) $tokenResp->json('error', '');
            if (str_contains($tokenResp->body(), 'AADSTS7000215') || $err === 'invalid_client') {
                $this->error('  >> CAUSA: el CLIENT SECRET de Azure esta MAL o EXPIRO.');
                $this->line('  >> Solucion: generar un secret nuevo en el App Registration de Azure');
                $this->line('     y actualizar EMAIL_AZURE_CLIENT_SECRET en el .env.');
            }
            return self::FAILURE;
        }

        $token = (string) $tokenResp->json('access_token', '');
        $this->info('  >> OK: token obtenido. El client_secret de Azure esta vigente.');
        $this->newLine();

        // ── 2. Pool de candidatos que el checker revisaria ───────────────────
        $this->info('[2/3] Contando el pool que "Verificar Rebotes" revisa...');

        $poolActual = NotifEmailLog::where('status', NotifEmailLog::STATUS_SENT)
            ->where('delivery_status', NotifEmailLog::DELIVERY_PENDING)
            ->where('fecha_intento', '>', now()->subHours(24))
            ->count();

        $sentHoy = NotifEmailLog::where('status', NotifEmailLog::STATUS_SENT)
            ->whereDate('created_at', today())
            ->count();

        $deliveredHoy = NotifEmailLog::where('delivery_status', NotifEmailLog::DELIVERY_DELIVERED)
            ->whereDate('created_at', today())
            ->count();

        $this->line('  Enviados hoy                     : ' . number_format($sentHoy));
        $this->line('  Marcados DELIVERED hoy           : ' . number_format($deliveredHoy));
        $this->line('  Pool revisable (SENT+PENDING 24h): ' . number_format($poolActual));
        $this->newLine();

        if ($poolActual === 0) {
            $this->warn('  >> El pool es 0: por eso "Verificar Rebotes" da "0 revisados".');
            $this->line('  >> Los correos se marcan DELIVERED a los 5 min (PendingEmailsWorkerJob),');
            $this->line('     antes de que llegue el NDR de Outlook. Salen del pool y no se');
            $this->line('     vuelven a revisar. Los rebotes tardios nunca se detectan.');
        } else {
            $this->info("  >> Hay {$poolActual} correos revisables ahora mismo.");
        }
        $this->newLine();

        // ── 3. NDR reales en el buzon (lo que el sistema NO ve) ──────────────
        $dias = max(1, (int) $this->option('days'));
        $this->info("[3/3] Buscando NDR (rebotes) reales en el buzon (ultimos {$dias} dias)...");

        $desde  = now()->subDays($dias)->startOfDay()->toISOString();
        $filter = "receivedDateTime ge {$desde}"
                . " and (from/emailAddress/address eq 'postmaster@outlook.com'"
                . " or contains(subject, 'Undeliverable')"
                . " or contains(subject, 'no se puede entregar')"
                . " or contains(subject, 'No se puede entregar'))";

        $resp = Http::withToken($token)
            ->timeout(20)
            ->get("https://graph.microsoft.com/v1.0/users/{$senderEmail}/mailFolders/inbox/messages", [
                '$filter'  => $filter,
                '$select'  => 'subject,receivedDateTime,from',
                '$top'     => 50,
                '$orderby' => 'receivedDateTime desc',
            ]);

        if ($resp->failed()) {
            $this->error('  >> Graph API rechazo la lectura del buzon: HTTP ' . $resp->status());
            $this->line('     ' . $resp->body());
            if ($resp->status() === 403) {
                $this->line('  >> CAUSA probable: falta el permiso Mail.Read (Application) en Azure,');
                $this->line('     o no se dio "Grant admin consent". Revisar el App Registration.');
            }
            return self::FAILURE;
        }

        $ndrs = $resp->json('value', []);
        $this->line('  NDR encontrados en el buzon: ' . count($ndrs));
        $this->newLine();

        if (count($ndrs) > 0) {
            $this->line('  Ultimos rebotes en el buzon:');
            foreach (array_slice($ndrs, 0, 10) as $m) {
                $this->line(sprintf('    [%s] %s',
                    substr((string) ($m['receivedDateTime'] ?? ''), 0, 16),
                    mb_strimwidth((string) ($m['subject'] ?? ''), 0, 70, '...')
                ));
            }
            $this->newLine();

            $ultimoBounceBd = NotifEmailLog::where('delivery_status', NotifEmailLog::DELIVERY_BOUNCED)
                ->max('bounce_detected_at');

            $this->warn('  >> El buzon TIENE ' . count($ndrs) . ' rebotes recientes.');
            $this->line('  >> Ultimo rebote registrado en la BD: ' . ($ultimoBounceBd ?? 'ninguno'));
            $this->line('  >> Si el buzon tiene rebotes de hoy pero la BD sigue en julio, se');
            $this->line('     confirma que el sistema NO los esta capturando (pool vaciado a los 5 min).');
        } else {
            $this->info('  >> No hay NDR recientes en el buzon en esta ventana.');
        }

        $this->newLine();
        $this->line(str_repeat('=', 64));
        $this->info('DIAGNOSTICO');
        $this->line(str_repeat('=', 64));
        $this->line('  Token Graph            : ' . ($token !== '' ? 'OK (secret vigente)' : 'FALLA'));
        $this->line('  Pool revisable ahora   : ' . number_format($poolActual));
        $this->line('  NDR reales en el buzon : ' . count($ndrs));
        $this->newLine();

        if ($token !== '' && count($ndrs) > 0 && $poolActual === 0) {
            $this->warn('  VEREDICTO: el token funciona y hay rebotes en el buzon, pero el pool');
            $this->warn('  esta vacio. El barrido (sweepRecentBounces) recupera esos rebotes');
            $this->warn('  emparejandolos contra los correos enviados, aunque ya figuren entregados.');
        }

        // ── Barrido real (opcional): marca los rebotes encontrados ───────────
        if ($this->option('sweep')) {
            $this->newLine();
            $this->info('[SWEEP] Ejecutando barrido real (marca los rebotes en la BD)...');

            $checker = app(\App\Services\Notificaciones\GraphBounceCheckerService::class);
            $res = $checker->sweepRecentBounces($dias);

            $this->line('  NDR revisados     : ' . ($res['checked'] ?? 0));
            $this->line('  Emparejados       : ' . ($res['matched'] ?? 0));
            $this->line('  Nuevos rebotes    : ' . ($res['bounced'] ?? 0));
            if (isset($res['message'])) {
                $this->warn('  Mensaje: ' . $res['message']);
            }

            if (($res['bounced'] ?? 0) > 0) {
                $this->info("  >> Se marcaron {$res['bounced']} correos como REBOTADOS. Ya aparecen en la pestaña Rebotados.");
            } else {
                $this->line('  >> No se marcaron nuevos rebotes (o ya estaban registrados).');
            }
        } else {
            $this->newLine();
            $this->line('  Para RECUPERAR los rebotes ahora, corra: php artisan notif:diagnose-bounces --days=7 --sweep');
        }

        return self::SUCCESS;
    }
}
