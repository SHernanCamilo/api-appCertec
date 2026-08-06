<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo (TV) emparejado con un tablero.
 *
 * Ciclo de vida:
 *   1. Admin crea → se genera pairing_code (6 dígitos, 5 min)
 *   2. TV ingresa código → se genera device_secret (permanente)
 *   3. TV usa device_secret para SSE → sesión permanente
 *   4. Admin puede revocar (active=false) en cualquier momento
 */
final class TableroDevice extends Model
{
    protected $table = 'tablero_devices';

    protected $fillable = [
        'pairing_code',
        'pairing_expires_at',
        'paired',
        'device_secret',
        'device_id',
        'fingerprint',
        'name',
        'schema_name',
        'view_name',
        'sede_filter',
        'active',
        'max_connections',
        'last_seen_at',
        'last_ip',
        'user_agent',
        'connection_count',
        'created_by',
    ];

    protected $casts = [
        'paired'              => 'boolean',
        'active'              => 'boolean',
        'pairing_expires_at'  => 'datetime',
        'last_seen_at'        => 'datetime',
        'max_connections'     => 'integer',
        'connection_count'    => 'integer',
    ];

    protected $hidden = ['device_secret', 'pairing_code'];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // =========================================================================
    // EMPAREJAMIENTO
    // =========================================================================

    /**
     * Genera un código de 6 dígitos único (no repetido entre los activos).
     */
    public static function generatePairingCode(): string
    {
        do {
            $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        } while (
            static::where('pairing_code', $code)
                ->where('pairing_expires_at', '>', now())
                ->exists()
        );

        return $code;
    }

    /**
     * Genera un device_secret seguro (48 chars hex).
     */
    public static function generateDeviceSecret(): string
    {
        return 'dev_' . bin2hex(random_bytes(22));
    }

    /**
     * Busca un código válido para emparejar.
     */
    public static function findByPairingCode(string $code): ?self
    {
        return static::where('pairing_code', $code)
            ->where('paired', false)
            ->where('pairing_expires_at', '>', now())
            ->where('active', true)
            ->first();
    }

    /**
     * Busca un dispositivo emparejado por su device_secret.
     */
    public static function findBySecret(string $secret): ?self
    {
        if (strlen($secret) < 10) {
            return null;
        }

        return static::where('device_secret', $secret)
            ->where('paired', true)
            ->where('active', true)
            ->first();
    }

    /**
     * Busca un dispositivo emparejado por fingerprint + IP (reconexión automática).
     *
     * Si la TV perdió el localStorage pero el backend la reconoce por su
     * fingerprint y la IP coincide con la última registrada, devuelve el device.
     */
    public static function findByFingerprint(string $fingerprint, string $ip): ?self
    {
        if (strlen($fingerprint) < 3) {
            return null;
        }

        return static::where('fingerprint', $fingerprint)
            ->where('last_ip', $ip)
            ->where('paired', true)
            ->where('active', true)
            ->latest('last_seen_at')
            ->first();
    }

    /**
     * Empareja el dispositivo: consume el código y genera el secret.
     */
    public function pair(string $ip, string $userAgent, string $deviceId = ''): string
    {
        $secret = self::generateDeviceSecret();

        $this->update([
            'paired'        => true,
            'device_secret' => $secret,
            'device_id'     => $deviceId !== '' ? $deviceId : $this->device_id,
            'pairing_code'  => null, // Invalidar código (un solo uso)
            'last_seen_at'  => now(),
            'last_ip'       => $ip,
            'user_agent'    => substr($userAgent, 0, 255),
        ]);

        return $secret;
    }

    /**
     * Registra actividad del dispositivo.
     */
    public function recordActivity(string $ip): void
    {
        $this->update([
            'last_seen_at'     => now(),
            'last_ip'          => $ip,
            'connection_count' => $this->connection_count + 1,
        ]);
    }

    /**
     * ¿El código de emparejamiento sigue vigente?
     */
    public function isPairingValid(): bool
    {
        return !$this->paired
            && $this->pairing_code !== null
            && $this->pairing_expires_at !== null
            && $this->pairing_expires_at->isFuture();
    }
}
