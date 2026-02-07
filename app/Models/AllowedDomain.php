<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AllowedDomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'tenant_id',
        'tenant_name',
        'id_empresa',
        'activo',
        'descripcion'
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * Relación con Empresa
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    /**
     * Verificar si un email está permitido
     */
    public static function isEmailAllowed(string $email): bool
    {
        $domain = '@' . substr(strrchr($email, "@"), 1);
        
        return self::where('domain', $domain)
            ->where('activo', 1)
            ->exists();
    }

    /**
     * Obtener dominio por email
     */
    public static function getByEmail(string $email)
    {
        $domain = '@' . substr(strrchr($email, "@"), 1);
        
        return self::where('domain', $domain)
            ->where('activo', 1)
            ->first();
    }

    /**
     * Scope para dominios activos
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', 1);
    }
}
