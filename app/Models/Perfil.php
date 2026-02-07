<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Perfil extends Model
{
    use HasFactory;

    protected $table = 'seg_perfiles';

    protected $fillable = [
        'nombre',
        'codigo',
        'id_modulo',
        'descripcion',
        'puede_crear',
        'puede_leer',
        'puede_editar',
        'puede_eliminar',
        'estado'
    ];

    protected $casts = [
        'puede_crear' => 'boolean',
        'puede_leer' => 'boolean',
        'puede_editar' => 'boolean',
        'puede_eliminar' => 'boolean',
        'estado' => 'boolean',
    ];

    /**
     * Relación con Módulo
     */
    public function modulo()
    {
        return $this->belongsTo(Modulo::class, 'id_modulo');
    }

    /**
     * Relación con Roles (muchos a muchos)
     */
    public function roles()
    {
        return $this->belongsToMany(
            Rol::class,
            'seg_rol_perfil',
            'id_perfil',
            'id_rol'
        )->withTimestamps();
    }

    /**
     * Relación con Permisos (muchos a muchos)
     */
    public function permisos()
    {
        return $this->belongsToMany(
            Permiso::class,
            'seg_perfil_permiso',
            'id_perfil',
            'id_permiso'
        )->withTimestamps();
    }

    /**
     * Scope para perfiles activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Scope para perfiles por módulo
     */
    public function scopePorModulo($query, $idModulo)
    {
        return $query->where('id_modulo', $idModulo);
    }

    /**
     * Verificar si tiene permiso de crear
     */
    public function puedeCrear(): bool
    {
        return $this->puede_crear;
    }

    /**
     * Verificar si tiene permiso de leer
     */
    public function puedeLeer(): bool
    {
        return $this->puede_leer;
    }

    /**
     * Verificar si tiene permiso de editar
     */
    public function puedeEditar(): bool
    {
        return $this->puede_editar;
    }

    /**
     * Verificar si tiene permiso de eliminar
     */
    public function puedeEliminar(): bool
    {
        return $this->puede_eliminar;
    }

    /**
     * Obtener permisos como array
     */
    public function obtenerPermisos(): array
    {
        return [
            'crear' => $this->puede_crear,
            'leer' => $this->puede_leer,
            'editar' => $this->puede_editar,
            'eliminar' => $this->puede_eliminar,
            'extras' => $this->permisos->pluck('codigo')->toArray(),
        ];
    }

    /**
     * Obtener todos los códigos de permisos (CRUD + extras)
     */
    public function obtenerCodigosPermisos(): array
    {
        $codigos = [];
        
        // Obtener el código base del módulo
        $modulo = $this->modulo;
        if ($modulo) {
            $codigoBase = strtolower(str_replace('_', '-', $modulo->codigo));
            
            // Agregar permisos CRUD
            if ($this->puede_crear) $codigos[] = "{$codigoBase}-crear";
            if ($this->puede_leer) $codigos[] = "{$codigoBase}-ver";
            if ($this->puede_editar) $codigos[] = "{$codigoBase}-editar";
            if ($this->puede_eliminar) $codigos[] = "{$codigoBase}-eliminar";
        }
        
        // Agregar permisos extras desde la relación
        $permisosExtras = $this->permisos()->where('estado', true)->pluck('codigo')->toArray();
        $codigos = array_merge($codigos, $permisosExtras);
        
        return array_unique($codigos);
    }
}
