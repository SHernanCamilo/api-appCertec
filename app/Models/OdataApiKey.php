<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdataApiKey extends Model
{
    protected $table = 'odata_api_keys';

    protected $fillable = [
        'user_id', 'name', 'key_hash', 'key_prefix',
        'active', 'expires_at', 'last_used_at', 'use_count', 'ip_last_used',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['key_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ¿La key es válida (activa y no expirada)?
     */
    public function isValid(): bool
    {
        if (!$this->active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    /**
     * Registrar uso de la key.
     */
    public function recordUse(?string $ip = null): void
    {
        $this->increment('use_count');
        $this->update([
            'last_used_at' => now(),
            'ip_last_used' => $ip,
        ]);
    }

    /**
     * Validar un API key contra el hash almacenado.
     */
    public static function validateKey(string $email, string $apiKey): ?self
    {
        $hash = hash('sha256', $apiKey);

        $record = self::where('key_hash', $hash)
            ->where('active', true)
            ->whereHas('user', fn($q) => $q->where('email', strtolower($email)))
            ->with('user')
            ->first();

        if (!$record || !$record->isValid()) {
            return null;
        }

        return $record;
    }

    /**
     * Generar un nuevo API Key.
     * @return array{key: string, prefix: string, hash: string}
     */
    public static function generateKey(): array
    {
        $key = 'jade_pk_' . bin2hex(random_bytes(24)); // jade_pk_ + 48 chars hex
        return [
            'key'    => $key,
            'prefix' => substr($key, 0, 16) . '...',
            'hash'   => hash('sha256', $key),
        ];
    }
}
