<?php

declare(strict_types=1);

namespace App\Services\ChatBot;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de seguridad para el ChatBot.
 *
 * Responsabilidades:
 *  - Detectar intentos de prompt injection
 *  - Validar que el usuario solo acceda a esquemas permitidos
 *  - Registrar intentos sospechosos en chatbot_security_logs
 *  - Sanitizar inputs del usuario
 *  - Bloquear preguntas fuera de contexto (infraestructura, credenciales, etc.)
 */
class ChatBotSecurityService
{
    /**
     * Patrones de prompt injection comunes.
     * El bot SOLO debe responder sobre datos de vistas Fabric.
     */
    private const INJECTION_PATTERNS = [
        // Intentos de cambiar el sistema prompt
        '/ignore\s+(previous|all|above)\s+(instructions?|prompts?)/i',
        '/forget\s+(everything|your|all)/i',
        '/you\s+are\s+now\s+a/i',
        '/act\s+as\s+(if|a|an)/i',
        '/pretend\s+(you|to\s+be)/i',
        '/new\s+instructions?/i',
        '/override\s+(system|your|the)/i',
        '/system\s*prompt/i',
        '/reveal\s+(your|the)\s+(instructions?|prompt|system)/i',
        '/what\s+are\s+your\s+(instructions?|rules)/i',
        '/show\s+me\s+(your|the)\s+(prompt|instructions?)/i',

        // Intentos de SQL injection via el bot
        '/DROP\s+TABLE/i',
        '/DELETE\s+FROM/i',
        '/INSERT\s+INTO/i',
        '/UPDATE\s+\w+\s+SET/i',
        '/ALTER\s+TABLE/i',
        '/CREATE\s+TABLE/i',
        '/TRUNCATE/i',
        '/;\s*(DROP|DELETE|INSERT|UPDATE|ALTER|CREATE|TRUNCATE)/i',
        '/UNION\s+SELECT/i',
        '/INTO\s+OUTFILE/i',
        '/LOAD_FILE/i',
        '/xp_cmdshell/i',

        // Intentos de extraer info del sistema
        '/env\s*\(/i',
        '/\.env\b/i',
        '/password|secret|token|credential|api[_\s]?key/i',
        '/conexi[oó]n|connection\s+string/i',
        '/cadena\s+de\s+conexi/i',
        '/servidor|server\s+(ip|address|host)/i',
        '/base\s*de\s*datos.*(nombre|name|host|password)/i',
    ];

    /**
     * Temas prohibidos: el bot no debe responder sobre estos tópicos.
     */
    private const FORBIDDEN_TOPICS = [
        '/infraestructura|servidores?|vps|cpu|ram|disco|deploy/i',
        '/contrase[ñn]a|password|clave\s+de\s+acceso/i',
        '/credencial|token\s+de\s+acceso|api\s*key/i',
        '/c[oó]digo\s+fuente|source\s*code|repositorio|github/i',
        '/configuraci[oó]n\s+(del\s+)?(servidor|sistema|laravel|php)/i',
        '/migra(ci[oó]n|te)|base\s+de\s+datos\s+(esquema|schema|estructura)/i',
        '/usuarios?\s+(del\s+)?sistema|admin|root/i',
        '/permisos?\s+(del\s+)?(sistema|admin)/i',
        '/cuántos\s+usuarios|list(ar|a)\s+usuarios/i',
        '/informaci[oó]n\s+(de|del)\s+(empleado|persona|c[eé]dula|documento)/i',
        '/salario|sueldo|n[oó]mina|pago|ganan\b/i',
        '/cadena\s+de\s+conexi[oó]n/i',
    ];

    /**
     * Valida el mensaje del usuario.
     *
     * @return array{safe: bool, reason?: string, type?: string}
     */
    public function validateMessage(string $message, User $user): array
    {
        $message = trim($message);

        // 1. Longitud
        $maxLength = config('chatbot.max_message_length', 1000);
        if (mb_strlen($message) > $maxLength) {
            return [
                'safe'   => false,
                'reason' => "El mensaje excede el límite de {$maxLength} caracteres.",
                'type'   => 'length_exceeded',
            ];
        }

        // 2. Mensaje vacío
        if ($message === '') {
            return [
                'safe'   => false,
                'reason' => 'El mensaje no puede estar vacío.',
                'type'   => 'empty_message',
            ];
        }

        // 3. Detección de prompt injection
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                $this->logSecurityEvent($user, 'injection_attempt', $message, "Patrón detectado: {$pattern}");
                return [
                    'safe'   => false,
                    'reason' => 'Tu mensaje contiene patrones no permitidos. Solo puedo responder consultas sobre datos de las vistas disponibles.',
                    'type'   => 'injection_attempt',
                ];
            }
        }

        // 4. Temas prohibidos
        foreach (self::FORBIDDEN_TOPICS as $pattern) {
            if (preg_match($pattern, $message)) {
                $this->logSecurityEvent($user, 'forbidden_topic', $message, "Tema prohibido: {$pattern}");
                return [
                    'safe'   => false,
                    'reason' => 'No puedo responder sobre ese tema. Mi función es ayudarte a consultar información de las vistas de datos disponibles para tu perfil.',
                    'type'   => 'forbidden_topic',
                ];
            }
        }

        return ['safe' => true];
    }

    /**
     * Valida que un esquema solicitado por el bot esté en los permisos del usuario.
     *
     * @param string[] $esquemasPermitidos
     */
    public function validateSchemaAccess(string $schema, array $esquemasPermitidos, User $user): bool
    {
        $schema = strtolower(trim($schema));
        $permitido = in_array($schema, $esquemasPermitidos, true);

        if (!$permitido) {
            $this->logSecurityEvent(
                $user,
                'schema_violation',
                "Intento de acceso a esquema '{$schema}'",
                "Esquemas permitidos: " . implode(', ', $esquemasPermitidos)
            );
        }

        return $permitido;
    }

    /**
     * Valida que la vista solicitada esté en el catálogo activo del bot.
     *
     * @param array $catalogoActivo Array de ['schema_name' => X, 'view_name' => Y]
     */
    public function validateViewInCatalog(string $schema, string $view, array $catalogoActivo): bool
    {
        foreach ($catalogoActivo as $item) {
            if (
                strtolower($item['schema_name']) === strtolower($schema) &&
                strtolower($item['view_name']) === strtolower($view)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sanitiza el output del LLM para evitar que devuelva información sensible.
     */
    public function sanitizeOutput(string $response): string
    {
        // Eliminar cualquier cosa que parezca un token/key/password
        $response = preg_replace('/sk-[a-zA-Z0-9\-_]{20,}/', '[REDACTED]', $response);
        $response = preg_replace('/Bearer\s+[a-zA-Z0-9\-_\.]{20,}/', 'Bearer [REDACTED]', $response);
        $response = preg_replace('/password\s*[:=]\s*\S+/i', 'password: [REDACTED]', $response);
        $response = preg_replace('/[a-f0-9]{32,}/i', '[HASH_REDACTED]', $response);

        // Eliminar IPs internas
        $response = preg_replace('/\b(192\.168|10\.|172\.(1[6-9]|2[0-9]|3[01]))\.\d+\.\d+\b/', '[IP_REDACTED]', $response);
        $response = preg_replace('/127\.0\.0\.\d+/', '[IP_REDACTED]', $response);

        return $response;
    }

    /**
     * Registra evento de seguridad.
     */
    private function logSecurityEvent(User $user, string $tipo, string $mensaje, ?string $detalle = null): void
    {
        try {
            DB::table('chatbot_security_logs')->insert([
                'user_id'          => $user->id,
                'tipo'             => $tipo,
                'mensaje_original' => mb_substr($mensaje, 0, 2000),
                'detalle'          => $detalle,
                'ip'               => request()->ip(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ChatBot: no se pudo registrar evento de seguridad', [
                'user'  => $user->id,
                'tipo'  => $tipo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verifica rate limiting: máximo de requests por hora.
     */
    public function checkRateLimit(User $user): bool
    {
        $limit = config('chatbot.rate_limit_per_hour', 30);

        $count = DB::table('chatbot_messages')
            ->join('chatbot_conversations', 'chatbot_conversations.id', '=', 'chatbot_messages.conversation_id')
            ->where('chatbot_conversations.user_id', $user->id)
            ->where('chatbot_messages.role', 'user')
            ->where('chatbot_messages.created_at', '>=', now()->subHour())
            ->count();

        return $count < $limit;
    }
}
