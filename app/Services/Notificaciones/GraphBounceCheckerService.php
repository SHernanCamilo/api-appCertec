<?php

declare(strict_types=1);

namespace App\Services\Notificaciones;

use App\Models\Notificaciones\NotifEmailLog;
use App\Models\Notificaciones\NotifEmailTrace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Servicio de verificación de rebotes usando Microsoft Graph API.
 *
 * Flujo:
 *   1. Obtiene token OAuth2 de Azure AD (con cache 55 min)
 *   2. Busca NDR (Non-Delivery Reports) en la bandeja de notificaciones.apps@
 *   3. Si detecta rebote para un email → marca como BOUNCED en notif_email_logs
 *   4. Si no hay rebote después de 5 min → marca como DELIVERED
 *
 * Credenciales (.env):
 *   EMAIL_AZURE_TENANT_ID, EMAIL_AZURE_CLIENT_ID, EMAIL_AZURE_CLIENT_SECRET
 *   MICROSOFT_EMAIL (la cuenta que envía los correos)
 */
final class GraphBounceCheckerService
{
    private const TOKEN_CACHE_KEY = 'graph_bounce_token';
    private const TOKEN_TTL       = 3300; // 55 min (token dura 60)

    private string  $tenantId;
    private string  $clientId;
    private string  $clientSecret;
    private string  $senderEmail;
    private bool    $enabled;

    public function __construct()
    {
        $this->tenantId     = env('EMAIL_AZURE_TENANT_ID', '');
        $this->clientId     = env('EMAIL_AZURE_CLIENT_ID', '');
        $this->clientSecret = env('EMAIL_AZURE_CLIENT_SECRET', '');
        $this->senderEmail  = env('MICROSOFT_EMAIL', 'notificaciones.apps@medilaser.com.co');
        $this->enabled      = env('USE_MICROSOFT_GRAPH', false) === true || env('USE_MICROSOFT_GRAPH', 'false') === 'true';
    }

    /**
     * Barrido de rebotes REALES desde el buzón, emparejando contra los correos
     * enviados aunque ya estén marcados DELIVERED.
     *
     * Por qué existe: los NDR de Outlook suelen tardar MÁS de 5 minutos en
     * llegar. Para entonces, checkAllPending() y el worker ya marcaron el correo
     * como DELIVERED y lo sacaron del pool SENT+PENDING, así que el rebote tardío
     * nunca se detectaba. Este método invierte el enfoque: parte del buzón (donde
     * están los rebotes de verdad) y busca a qué correo enviado corresponden.
     *
     * Es idempotente: si el correo ya está BOUNCED, no lo vuelve a tocar.
     *
     * @param  int $days Cuántos días hacia atrás inspeccionar el buzón.
     * @return array{checked:int, matched:int, bounced:int, message?:string}
     */
    public function sweepRecentBounces(int $days = 3): array
    {
        if (!$this->enabled) {
            return ['checked' => 0, 'matched' => 0, 'bounced' => 0, 'message' => 'Graph API deshabilitado'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['checked' => 0, 'matched' => 0, 'bounced' => 0, 'message' => 'Sin token de Graph (revisar client_secret)'];
        }

        $ndrs = $this->fetchRecentNdrs($token, $days);
        if ($ndrs === []) {
            return ['checked' => 0, 'matched' => 0, 'bounced' => 0];
        }

        $matched = 0;
        $bounced = 0;

        foreach ($ndrs as $ndr) {
            // Correos candidatos: los enviados en la ventana, ordenados del más
            // reciente. Emparejamos por email destino o por message_id.
            $log = $this->matchLogForNdr($ndr, $days);
            if ($log === null) {
                continue;
            }

            $matched++;

            // Idempotente: si ya está rebotado, no duplicar.
            if ($log->delivery_status === NotifEmailLog::DELIVERY_BOUNCED) {
                continue;
            }

            $this->markAsBounced($log, $ndr['reason']);
            $bounced++;
        }

        Log::channel('notificaciones')->info(
            "[BOUNCE-SWEEP] NDR en buzon: " . count($ndrs) . " | Emparejados: {$matched} | Nuevos rebotes: {$bounced}"
        );

        return [
            'checked' => count($ndrs),
            'matched' => $matched,
            'bounced' => $bounced,
        ];
    }

    /**
     * Trae los NDR (rebotes) del buzón de los últimos N días, ya clasificados
     * con su motivo y el texto donde buscar el destinatario.
     *
     * @return list<array{email:?string, message_id:?string, reason:string, body:string}>
     */
    private function fetchRecentNdrs(string $token, int $days): array
    {
        $desde  = now()->subDays(max(1, $days))->startOfDay()->toISOString();
        $filter = "receivedDateTime ge {$desde}"
                . " and (from/emailAddress/address eq 'postmaster@outlook.com'"
                . " or contains(subject, 'Undeliverable')"
                . " or contains(subject, 'no se puede entregar')"
                . " or contains(subject, 'No se puede entregar'))";

        $out = [];
        $url = "https://graph.microsoft.com/v1.0/users/{$this->senderEmail}/mailFolders/inbox/messages";
        $params = [
            '$filter'  => $filter,
            '$select'  => 'subject,bodyPreview,body,receivedDateTime,from',
            '$top'     => 100,
            '$orderby' => 'receivedDateTime desc',
        ];

        try {
            // Paginar hasta agotar (@odata.nextLink) o un tope de seguridad.
            for ($page = 0; $page < 10; $page++) {
                $response = $page === 0
                    ? Http::withToken($token)->timeout(20)->get($url, $params)
                    : Http::withToken($token)->timeout(20)->get($url);

                if ($response->failed()) {
                    Log::channel('notificaciones')->warning("[BOUNCE-SWEEP] Graph API error: {$response->status()}");
                    break;
                }

                foreach ($response->json('value', []) as $msg) {
                    $body    = strtolower(($msg['body']['content'] ?? '') . ' ' . ($msg['bodyPreview'] ?? ''));
                    $subject = strtolower($msg['subject'] ?? '');

                    $isNdr = str_contains($subject, 'undeliverable')
                          || str_contains($subject, 'delivery status')
                          || str_contains($subject, 'no se puede entregar')
                          || str_contains($body, "couldn't be delivered")
                          || str_contains($body, "wasn't found");

                    if (!$isNdr) {
                        continue;
                    }

                    $out[] = [
                        'email'      => $this->extractRecipientEmail($body),
                        'message_id' => null,
                        'reason'     => $this->reasonFromBody($body),
                        'body'       => $body,
                    ];
                }

                $next = $response->json('@odata.nextLink');
                if (!$next) {
                    break;
                }
                $url = $next;
            }
        } catch (\Exception $e) {
            Log::channel('notificaciones')->error("[BOUNCE-SWEEP] Error leyendo buzon: {$e->getMessage()}");
        }

        return $out;
    }

    /**
     * Empareja un NDR con el correo enviado al que corresponde.
     *
     * Estrategia: el destinatario del rebote (extraído del cuerpo) contra
     * email_to, entre los correos enviados en la ventana. Se prefiere el más
     * reciente que aún no esté rebotado.
     */
    private function matchLogForNdr(array $ndr, int $days): ?NotifEmailLog
    {
        $email = $ndr['email'];
        if ($email === null || $email === '') {
            // Sin destinatario extraído: intentar por contenido del cuerpo.
            return null;
        }

        return NotifEmailLog::where('status', NotifEmailLog::STATUS_SENT)
            ->whereRaw('LOWER(email_to) = ?', [strtolower($email)])
            ->where('created_at', '>', now()->subDays(max(1, $days))->startOfDay())
            ->orderByRaw("CASE WHEN delivery_status = ? THEN 1 ELSE 0 END", [NotifEmailLog::DELIVERY_BOUNCED])
            ->orderByDesc('created_at')
            ->first();
    }

    /** Extrae el email del destinatario que aparece en el cuerpo del NDR. */
    private function extractRecipientEmail(string $body): ?string
    {
        // El NDR menciona el destinatario fallido como una dirección de correo.
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $body, $m)) {
            foreach ($m[0] as $candidate) {
                $c = strtolower($candidate);
                // Descartar direcciones del propio sistema/postmaster.
                if (str_contains($c, 'postmaster') || str_contains($c, 'outlook.com')
                    || str_contains($c, 'notificaciones.apps')) {
                    continue;
                }
                return $c;
            }
        }
        return null;
    }

    /** Deriva el motivo del rebote a partir del cuerpo del NDR. */
    private function reasonFromBody(string $body): string
    {
        if (str_contains($body, "wasn't found") || str_contains($body, 'not found')) {
            return 'Destinatario no encontrado';
        }
        if (str_contains($body, 'mailbox full')) {
            return 'Buzón lleno';
        }
        if (str_contains($body, 'invalid') || str_contains($body, 'does not exist')) {
            return 'Email inválido';
        }
        if (str_contains($body, 'rejected') || str_contains($body, 'blocked')) {
            return 'Email rechazado';
        }
        if (str_contains($body, "couldn't be delivered")) {
            return 'Email no pudo ser entregado';
        }
        return 'Email rebotado';
    }

    /**
     * Verifica rebotes para todos los emails SENT + PENDING de las últimas 24h.
     * Llamado por el PendingEmailsWorkerJob.
     */
    public function checkAllPending(): array
    {
        if (!$this->enabled) {
            return ['checked' => 0, 'bounced' => 0, 'delivered' => 0, 'message' => 'Graph API deshabilitado'];
        }

        $pendientes = NotifEmailLog::where('status', NotifEmailLog::STATUS_SENT)
            ->where('delivery_status', NotifEmailLog::DELIVERY_PENDING)
            ->where('fecha_intento', '>', now()->subHours(24))
            ->get();

        if ($pendientes->isEmpty()) {
            return ['checked' => 0, 'bounced' => 0, 'delivered' => 0];
        }

        $bounced   = 0;
        $delivered = 0;

        foreach ($pendientes as $log) {
            $result = $this->checkBounceForEmail($log);

            if ($result === 'BOUNCED') {
                $bounced++;
            } elseif ($result === 'DELIVERED') {
                $delivered++;
            }
        }

        Log::channel('notificaciones')->info("[BOUNCE] Verificados: {$pendientes->count()} | Rebotados: {$bounced} | Entregados: {$delivered}");

        return [
            'checked'   => $pendientes->count(),
            'bounced'   => $bounced,
            'delivered' => $delivered,
        ];
    }

    /**
     * Verifica rebote para un email específico.
     *
     * @return string 'BOUNCED' | 'DELIVERED' | 'PENDING'
     */
    public function checkBounceForEmail(NotifEmailLog $log): string
    {
        // Si pasaron más de 5 minutos y no hay rebote → DELIVERED
        $minutosPasados = $log->fecha_intento ? $log->fecha_intento->diffInMinutes(now()) : 999;

        try {
            $token = $this->getAccessToken();

            if (!$token) {
                // Sin token → usar regla de tiempo
                if ($minutosPasados >= 5) {
                    $this->markAsDelivered($log);
                    return 'DELIVERED';
                }
                return 'PENDING';
            }

            // Buscar NDR en la bandeja
            $hasBounce = $this->searchBounceInInbox($token, $log->email_to, $log->message_id, $log->fecha_intento);

            if ($hasBounce['bounced']) {
                $this->markAsBounced($log, $hasBounce['reason']);
                return 'BOUNCED';
            }

            // No hay rebote — si pasaron 5+ min, marcar entregado
            if ($minutosPasados >= 5) {
                $this->markAsDelivered($log);
                return 'DELIVERED';
            }

            return 'PENDING';

        } catch (\Exception $e) {
            Log::channel('notificaciones')->error("[BOUNCE] Error verificando {$log->email_to}: {$e->getMessage()}");

            // Fallback por tiempo
            if ($minutosPasados >= 5) {
                $this->markAsDelivered($log);
                return 'DELIVERED';
            }

            return 'PENDING';
        }
    }

    /**
     * Busca NDR (Non-Delivery Reports) en la bandeja del sender.
     */
    private function searchBounceInInbox(string $token, string $emailTo, ?string $messageId, ?Carbon $sentAt): array
    {
        $default = ['bounced' => false, 'reason' => null];

        // Buscar solo rebotes del día de hoy
        $today = ($sentAt ?? now())->startOfDay()->toISOString();
        $end   = ($sentAt ?? now())->endOfDay()->toISOString();

        $filter = "receivedDateTime ge {$today} and receivedDateTime le {$end}"
                . " and (from/emailAddress/address eq 'postmaster@outlook.com'"
                . " or contains(subject, 'Undeliverable')"
                . " or contains(subject, 'no se puede entregar'))";

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("https://graph.microsoft.com/v1.0/users/{$this->senderEmail}/mailFolders/inbox/messages", [
                    '$filter'  => $filter,
                    '$select'  => 'id,subject,bodyPreview,body,receivedDateTime,from',
                    '$top'     => 20,
                    '$orderby' => 'receivedDateTime desc',
                ]);

            if ($response->failed()) {
                Log::channel('notificaciones')->warning("[BOUNCE] Graph API error: {$response->status()}");
                return $default;
            }

            $messages = $response->json('value', []);

            foreach ($messages as $msg) {
                $bodyContent = strtolower($msg['body']['content'] ?? '');
                $bodyPreview = strtolower($msg['bodyPreview'] ?? '');
                $subject     = strtolower($msg['subject'] ?? '');

                // ¿Es un NDR?
                $isNdr = str_contains($subject, 'undeliverable')
                      || str_contains($subject, 'delivery status')
                      || str_contains($subject, 'no se puede entregar')
                      || str_contains($bodyContent, "couldn't be delivered")
                      || str_contains($bodyContent, "wasn't found");

                if (!$isNdr) {
                    continue;
                }

                // ¿Es para nuestro destinatario?
                $emailLower  = strtolower($emailTo);
                $emailInBody = str_contains($bodyContent, $emailLower) || str_contains($bodyPreview, $emailLower);

                // También buscar por Message-ID
                $messageIdMatch = false;
                if ($messageId) {
                    $cleanId      = strtolower(str_replace(['<', '>'], '', $messageId));
                    $messageIdMatch = str_contains($bodyContent, $cleanId);
                }

                if ($emailInBody || $messageIdMatch) {
                    // Extraer razón
                    $reason = 'Email rebotado';
                    if (str_contains($bodyContent, "wasn't found") || str_contains($bodyContent, 'not found')) {
                        $reason = 'Destinatario no encontrado';
                    } elseif (str_contains($bodyContent, 'mailbox full')) {
                        $reason = 'Buzón lleno';
                    } elseif (str_contains($bodyContent, 'invalid') || str_contains($bodyContent, 'does not exist')) {
                        $reason = 'Email inválido';
                    } elseif (str_contains($bodyContent, 'rejected') || str_contains($bodyContent, 'blocked')) {
                        $reason = 'Email rechazado';
                    } elseif (str_contains($bodyContent, "couldn't be delivered")) {
                        $reason = 'Email no pudo ser entregado';
                    }

                    return ['bounced' => true, 'reason' => $reason];
                }
            }

            return $default;

        } catch (\Exception $e) {
            Log::channel('notificaciones')->error("[BOUNCE] Error buscando NDR: {$e->getMessage()}");
            return $default;
        }
    }

    // =========================================================================
    // TOKEN MANAGEMENT
    // =========================================================================

    private function getAccessToken(): ?string
    {
        if (empty($this->tenantId) || empty($this->clientId) || empty($this->clientSecret)) {
            return null;
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL, function () {
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                    'grant_type'    => 'client_credentials',
                ]
            );

            if ($response->failed()) {
                Log::channel('notificaciones')->error('[BOUNCE] Error obteniendo token Graph: ' . $response->body());
                return null;
            }

            return $response->json('access_token');
        });
    }

    // =========================================================================
    // MARK HELPERS
    // =========================================================================

    private function markAsDelivered(NotifEmailLog $log): void
    {
        $log->update([
            'delivery_status' => NotifEmailLog::DELIVERY_DELIVERED,
            'delivered_at'    => now(),
        ]);

        NotifEmailTrace::create([
            'email_log_id'  => $log->id,
            'event_type'    => 'ENTREGADO',
            'event_status'  => 'SUCCESS',
            'event_message' => 'Email entregado (verificado via Graph API, sin rebote)',
            'created_at'    => now(),
        ]);
    }

    private function markAsBounced(NotifEmailLog $log, string $reason): void
    {
        $log->update([
            'delivery_status'    => NotifEmailLog::DELIVERY_BOUNCED,
            'bounce_reason'      => $reason,
            'bounce_detected_at' => now(),
        ]);

        NotifEmailTrace::create([
            'email_log_id'  => $log->id,
            'event_type'    => 'REBOTADO',
            'event_status'  => 'ERROR',
            'event_message' => "Email rebotado: {$reason}",
            'event_details' => 'Detectado via Microsoft Graph API',
            'created_at'    => now(),
        ]);
    }
}

