<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sucursal;
use App\Models\Sede;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tipo_identificacion',
        'numero_identificacion',
        'direccion',
        'telefono',
        'id_sucursal',
        'id_sede',
        'estado',
        'microsoft_id',
        'tenant_id',
        'avatar',
        'auth_type',
        'cargo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'estado' => 'boolean',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Relación muchos a muchos con empresas
     */
    public function empresas()
    {
        return $this->belongsToMany(Empresa::class, 'seg_empresa_user', 'user_id', 'empresa_id')
                    ->withPivot('id_sucursal', 'id_sede', 'recursivo')
                    ->withTimestamps();
    }

    /**
     * Relación muchos a muchos con roles personalizados
     */
    public function rolesCustom()
    {
        return $this->belongsToMany(Rol::class, 'seg_rol_user', 'user_id', 'rol_id');
    }

    /**
     * Relación con sucursal
     */
    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    /**
     * Relación con sede
     */
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    /**
     * Scope para usuarios activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }

    /**
     * Scope para usuarios inactivos
     */
    public function scopeInactivos($query)
    {
        return $query->where('estado', 0);
    }

    /**
     * Verificar si el usuario está activo
     */
    public function estaActivo(): bool
    {
        return $this->estado == 1;
    }

    /**
     * Activar usuario
     */
    public function activar(): bool
    {
        return $this->update(['estado' => 1]);
    }

    /**
     * Inactivar usuario
     */
    public function inactivar(): bool
    {
        return $this->update(['estado' => 0]);
    }
}
