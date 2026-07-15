<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdataApiKey extends Model
{
    protected $table = 'odata_api_keys';

    public const SCOPE_PRIVATE = 'private';
    public const SCOPE_SHARED  = 'shared';

    protected $fillable = [
        'user_id', 'name', 'key_hash', 'key_prefix', 'scope',
        'active', 'expires_at', 'last_used_at', 'use_count', 'ip_last_used',
    ];

    protected $casts = [
        'active'       => 'boolean',
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $attributes = [
        'scope' => 'private',
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
     * ¿Es una key compartida?
     */
    public function isShared(): bool
    {
        return $this->scope === self::SCOPE_SHARED;
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
     *
     * Flujo:
     * - Key PRIVATE: el email debe coincidir con el dueño de la key.
     * - Key SHARED:  cualquier @medilaser.com.co puede usarla (se valida permiso después).
     *
     * @param string $email Email del usuario que intenta autenticarse
     * @param string $apiKey La key en texto plano
     * @return array{record: self, user: User}|null
     */
    public static function validateKey(string $email, string $apiKey): ?self
    {
        $hash = hash('sha256', $apiKey);

        // Primero buscar key PRIVADA (match exacto: hash + email del dueño)
        $record = self::where('key_hash', $hash)
            ->where('active', true)
            ->where('scope', self::SCOPE_PRIVATE)
            ->whereHas('user', fn($q) => $q->where('email', strtolower($email)))
            ->with('user')
            ->first();

        if ($record && $record->isValid()) {
            return $record;
        }

        // Si no encontró privada, buscar key COMPARTIDA (solo match por hash)
        $record = self::where('key_hash', $hash)
            ->where('active', true)
            ->where('scope', self::SCOPE_SHARED)
            ->with('user')
            ->first();

        if ($record && $record->isValid()) {
            // Para shared keys, el "user" asociado es quien la creó,
            // pero el email del request es el usuario real que accede.
            // Se devuelve el record y la validación de permisos la hace el controlador.
            return $record;
        }

        return null;
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
