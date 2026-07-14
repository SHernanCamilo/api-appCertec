<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OdataLink extends Model
{
    use SoftDeletes;

    protected $table = 'odata_links';

    public const VISIBILITY_PRIVATE        = 'private';
    public const VISIBILITY_ORGANIZATIONAL = 'organizational';
    public const VISIBILITY_PUBLIC         = 'public';

    protected $fillable = [
        'code', 'name', 'visibility', 'created_by', 'created_by_email',
        'schema_name', 'view_name', 'columns', 'filters',
        'sort_col', 'sort_dir', 'max_rows',
        'token_hash', 'expires_at', 'allowed_ips', 'allowed_users',
        'active', 'access_count', 'last_accessed_at',
    ];

    protected $attributes = [
        'active'       => true,
        'access_count' => 0,
        'sort_dir'     => 'asc',
        'max_rows'     => 100000,
    ];

    protected $casts = [
        'columns'          => 'array',
        'filters'          => 'array',
        'allowed_ips'      => 'array',
        'allowed_users'    => 'array',
        'active'           => 'boolean',
        'expires_at'       => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // =========================================================================
    // VALIDACIONES DE ACCESO
    // =========================================================================

    /**
     * ¿El link está activo y no expirado?
     */
    public function isValid(): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    /**
     * ¿El usuario puede acceder según el nivel de visibilidad?
     */
    public function canAccess(?string $userEmail, ?string $ip = null): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        // Validar IP si está restringido
        if ($this->allowed_ips && $ip) {
            if (!in_array($ip, $this->allowed_ips, true)) {
                return false;
            }
        }

        switch ($this->visibility) {
            case self::VISIBILITY_PRIVATE:
                // Solo el creador
                return $userEmail && strtolower($userEmail) === strtolower($this->created_by_email);

            case self::VISIBILITY_ORGANIZATIONAL:
                // Cualquier @medilaser.com.co (o lista específica)
                if (!$userEmail) return false;
                if ($this->allowed_users) {
                    return in_array(strtolower($userEmail), array_map('strtolower', $this->allowed_users), true);
                }
                return str_ends_with(strtolower($userEmail), '@medilaser.com.co');

            case self::VISIBILITY_PUBLIC:
                // Cualquiera con token válido (se valida aparte)
                return true;

            default:
                return false;
        }
    }

    /**
     * Verificar token público (HMAC)
     */
    public function validatePublicToken(string $token): bool
    {
        if ($this->visibility !== self::VISIBILITY_PUBLIC) {
            return false;
        }
        return hash_equals($this->token_hash ?? '', hash('sha256', $token));
    }

    /**
     * Registrar un acceso
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }

    // =========================================================================
    // GENERACIÓN DE LINKS
    // =========================================================================

    /**
     * Genera la URL OData completa para este link.
     * Usa la ruta pública /api/fabric/odata/link/{code} que NO requiere auth:api.
     */
    public function getOdataUrl(): string
    {
        return url("/api/fabric/odata/link/{$this->code}");
    }

    /**
     * Genera un código único para el link.
     */
    public static function generateCode(): string
    {
        return bin2hex(random_bytes(16)); // 32 chars hex
    }

    /**
     * Genera un token público y su hash.
     * @return string El token en texto plano (solo se muestra una vez)
     */
    public static function generatePublicToken(): array
    {
        $token = bin2hex(random_bytes(32)); // 64 chars
        $hash  = hash('sha256', $token);
        return ['token' => $token, 'hash' => $hash];
    }
}
