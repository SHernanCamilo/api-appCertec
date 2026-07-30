<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token de acceso público para tableros informativos.
 *
 * Cada TV en una sala de espera tiene un token que:
 *   - Identifica qué sede y vista mostrar
 *   - Puede revocarse individualmente
 *   - Registra última IP y uso para auditoría
 *   - Limita conexiones SSE simultáneas
 *
 * @property int         $id
 * @property string      $token
 * @property string      $name
 * @property string      $schema_name
 * @property string      $view_name
 * @property string|null $sede_filter
 * @property bool        $active
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used_at
 * @property string|null $last_ip
 * @property int         $use_count
 * @property int         $max_connections
 * @property int|null    $created_by
 */
final class TableroToken extends Model
{
    protected $table = 'tablero_tokens';

    protected $fillable = [
        'token',
        'name',
        'schema_name',
        'view_name',
        'sede_filter',
        'active',
        'expires_at',
        'max_connections',
        'created_by',
    ];

    protected $casts = [
        'active'      => 'boolean',
        'expires_at'  => 'datetime',
        'last_used_at'=> 'datetime',
        'use_count'   => 'integer',
        'max_connections' => 'integer',
    ];

    protected $hidden = ['token'];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // =========================================================================
    // VALIDACIÓN
    // =========================================================================

    /**
     * ¿El token es válido en este momento?
     */
    public function isValid(): bool
    {
        if (!$this->active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Registra un uso del token (IP + timestamp + contador).
     */
    public function recordUse(string $ip): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_ip'      => $ip,
            'use_count'    => $this->use_count + 1,
        ]);
    }

    // =========================================================================
    // FACTORY
    // =========================================================================

    /**
     * Genera un token seguro de 48 caracteres hex (192 bits de entropía).
     */
    public static function generateToken(): string
    {
        return 'tb_' . bin2hex(random_bytes(24));
    }

    /**
     * Busca un token activo y válido.
     */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) < 10) {
            return null;
        }

        $record = static::where('token', $token)
            ->where('active', true)
            ->first();

        if ($record === null || !$record->isValid()) {
            return null;
        }

        return $record;
    }
}
