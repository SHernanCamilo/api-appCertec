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
use App\Models\UserGrup;

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
     * Relación muchos a muchos con empresas.
     * Un usuario puede tener múltiples sucursales de la misma empresa.
     */
    public function empresas()
    {
        return $this->belongsToMany(Empresa::class, 'seg_empresa_user', 'user_id', 'empresa_id')
                    ->withPivot('id', 'id_sucursal', 'id_sede', 'recursivo')
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
     * Grupos/permisos del tenant (vistas BD, departamento, etc.)
     */
    public function usersGrups()
    {
        return $this->hasMany(UserGrup::class, 'id_user');
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
     * Scope: usuarios que poseen un permiso por su código (seg_permisos.codigo)
     * a través de la cadena Rol -> Perfil -> Permiso.
     *
     * Usado por el motor de flujos para resolver aprobadores por permiso
     * (ej: 'apro-evento', 'auto-evento', 'digi-evento').
     *
     * @param string   $codigo         Código del permiso en seg_permisos
     * @param int|null $empresaId      Si se indica, limita a usuarios vinculados a esa empresa
     * @param bool     $incluirAdmins  Si true, los roles con es_admin también califican
     */
    public function scopeConPermiso($query, string $codigo, ?int $empresaId = null, bool $incluirAdmins = true)
    {
        return $query->where('users.estado', 1)
            ->where(function ($q) use ($codigo, $incluirAdmins) {
                $q->whereHas('rolesCustom', function ($rol) use ($codigo) {
                    $rol->where('seg_roles_custom.estado', true)
                        ->whereHas('perfiles', function ($perfil) use ($codigo) {
                            $perfil->where('seg_perfiles.estado', true)
                                ->whereHas('permisos', function ($permiso) use ($codigo) {
                                    $permiso->where('seg_permisos.codigo', $codigo)
                                        ->where('seg_permisos.estado', true);
                                });
                        });
                });

                if ($incluirAdmins) {
                    $q->orWhereHas('rolesCustom', function ($rol) {
                        $rol->where('seg_roles_custom.estado', true)
                            ->where('seg_roles_custom.es_admin', true);
                    });
                }
            })
            ->when($empresaId, function ($q, $id) {
                $q->whereHas('empresas', function ($empresa) use ($id) {
                    $empresa->where('ent_empresas.id', $id);
                });
            });
    }

    /**
     * Verificar si el usuario está activo
     */
    public function estaActivo(): bool
    {
        return $this->estado == 1;
    }

    public function esAdministrador(): bool
    {
        return $this->rolesCustom()->where('es_admin', true)->exists();
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

    /**
     * Vistas permitidas para actualizar por OData (Excel)
     */
    public function vistasPermitidasOData()
    {
        return $this->belongsToMany(\App\Models\BiVista::class, 'bi_vista_user_permissions', 'user_id', 'bi_vista_id');
    }
}
