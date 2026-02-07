<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla
     */
    protected $table = 'ent_empresas';

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'nombre',
        'prefijo',
        'rep_legal',
        'cc_rep_legal',
        'direccion',
        'telefono',
        'nit',
        'logo',
        'estado'
    ];

    /**
     * Campos que deben ser casteados
     */
    protected $casts = [
        'estado' => 'integer',
        'cc_rep_legal' => 'integer',
        'telefono' => 'integer',
        'nit' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Valores por defecto
     */
    protected $attributes = [
        'estado' => 1
    ];

    /**
     * Scope para empresas activas
     */
    public function scopeActivas($query)
    {
        return $query->where('estado', 1);
    }

    /**
     * Scope para empresas inactivas
     */
    public function scopeInactivas($query)
    {
        return $query->where('estado', 0);
    }

    /**
     * Relación con Sucursales
     */
    public function sucursales()
    {
        return $this->hasMany(Sucursal::class, 'id_Empresa');
    }

    /**
     * Relación con Módulos (muchos a muchos)
     */
    public function modulos()
    {
        return $this->belongsToMany(Modulo::class, 'seg_modulo_empresa', 'id_empresa', 'id_modulo')
            ->withPivot('activo', 'hereda_hijos')
            ->withTimestamps();
    }

    /**
     * Obtener módulos activos de la empresa
     */
    public function modulosActivos()
    {
        return $this->modulos()->wherePivot('activo', 1);
    }

    /**
     * Verificar si la empresa tiene acceso a un módulo
     */
    public function tieneAccesoModulo($codigoModulo)
    {
        $modulo = Modulo::where('codigo', $codigoModulo)->first();
        
        if (!$modulo) {
            return false;
        }

        return $modulo->empresaTieneAcceso($this->id);
    }

    /**
     * Obtener todos los módulos accesibles (incluyendo heredados)
     */
    public function obtenerModulosAccesibles($soloRaiz = false)
    {
        return ModuloEmpresa::obtenerModulosEmpresa($this->id, $soloRaiz);
    }

    /**
     * Relación muchos a muchos con usuarios
     */
    public function usuarios()
    {
        return $this->belongsToMany(User::class, 'seg_empresa_user', 'empresa_id', 'user_id');
    }
}
