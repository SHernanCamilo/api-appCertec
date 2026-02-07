<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    use HasFactory;

    protected $table = 'seg_roles_custom';

    protected $fillable = [
        'nombre',
        'codigo',
        'id_empresa',
        'descripcion',
        'es_admin',
        'estado'
    ];

    protected $casts = [
        'es_admin' => 'boolean',
        'estado' => 'boolean',
    ];

    /**
     * Relación con Empresa
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    /**
     * Relación con Perfiles (muchos a muchos)
     */
    public function perfiles()
    {
        return $this->belongsToMany(
            Perfil::class,
            'seg_rol_perfil',
            'id_rol',
            'id_perfil'
        )->withTimestamps();
    }

    /**
     * Relación con Usuarios (muchos a muchos)
     */
    public function usuarios()
    {
        return $this->belongsToMany(
            User::class,
            'seg_usuario_rol',
            'id_rol',
            'user_id'
        )->withPivot('id_empresa')->withTimestamps();
    }

    /**
     * Scope para roles activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Scope para roles globales (sin empresa específica)
     */
    public function scopeGlobales($query)
    {
        return $query->whereNull('id_empresa');
    }

    /**
     * Scope para roles de una empresa específica
     */
    public function scopePorEmpresa($query, $idEmpresa)
    {
        return $query->where('id_empresa', $idEmpresa);
    }

    /**
     * Scope para roles administradores
     */
    public function scopeAdministradores($query)
    {
        return $query->where('es_admin', true);
    }

    /**
     * Verificar si el rol es global
     */
    public function esGlobal(): bool
    {
        return is_null($this->id_empresa);
    }

    /**
     * Verificar si el rol es administrador
     */
    public function esAdministrador(): bool
    {
        return $this->es_admin;
    }

    /**
     * Obtener todos los permisos del rol (a través de sus perfiles)
     */
    public function obtenerPermisos()
    {
        $permisos = [];
        
        foreach ($this->perfiles as $perfil) {
            $permisos[] = [
                'modulo' => $perfil->modulo->nombre,
                'perfil' => $perfil->nombre,
                'puede_crear' => $perfil->puede_crear,
                'puede_leer' => $perfil->puede_leer,
                'puede_editar' => $perfil->puede_editar,
                'puede_eliminar' => $perfil->puede_eliminar,
                'permisos_extra' => $perfil->permisos_extra,
            ];
        }
        
        return $permisos;
    }

    /**
     * Asignar perfiles al rol
     */
    public function asignarPerfiles(array $perfilesIds)
    {
        $this->perfiles()->sync($perfilesIds);
    }

    /**
     * Agregar un perfil al rol
     */
    public function agregarPerfil($perfilId)
    {
        if (!$this->perfiles()->where('id_perfil', $perfilId)->exists()) {
            $this->perfiles()->attach($perfilId);
        }
    }

    /**
     * Remover un perfil del rol
     */
    public function removerPerfil($perfilId)
    {
        $this->perfiles()->detach($perfilId);
    }
}
