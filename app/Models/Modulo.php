<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Modulo extends Model
{
    use HasFactory;

    protected $table = 'seg_modulos';

    protected $fillable = [
        'id_modulo_padre',
        'nombre',
        'codigo',
        'descripcion',
        'icono',
        'ruta',
        'orden',
        'nivel',
        'estado'
    ];

    protected $casts = [
        'estado' => 'boolean',
        'nivel' => 'integer',
        'orden' => 'integer'
    ];

    // Relación: Módulo padre
    public function padre()
    {
        return $this->belongsTo(Modulo::class, 'id_modulo_padre');
    }

    // Relación: Módulos hijos
    public function hijos()
    {
        return $this->hasMany(Modulo::class, 'id_modulo_padre')->orderBy('orden');
    }

    // Relación: Todos los descendientes (recursivo)
    public function descendientes()
    {
        return $this->hijos()->with('descendientes');
    }

    // Relación: Empresas que tienen acceso a este módulo
    public function empresas()
    {
        return $this->belongsToMany(Empresa::class, 'seg_modulo_empresa', 'id_modulo', 'id_empresa')
            ->withPivot('activo', 'hereda_hijos')
            ->withTimestamps();
    }

    // Relación: Perfiles del módulo
    public function perfiles()
    {
        return $this->hasMany(Perfil::class, 'id_modulo');
    }

    // Scope: Solo módulos raíz (sin padre)
    public function scopeRaiz($query)
    {
        return $query->whereNull('id_modulo_padre');
    }

    // Scope: Solo módulos activos
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }

    // Método: Obtener todos los IDs de módulos hijos (recursivo)
    public function obtenerIdsHijos()
    {
        $ids = [];
        foreach ($this->hijos as $hijo) {
            $ids[] = $hijo->id;
            $ids = array_merge($ids, $hijo->obtenerIdsHijos());
        }
        return $ids;
    }

    // Método: Verificar si una empresa tiene acceso (considerando herencia)
    public function empresaTieneAcceso($idEmpresa)
    {
        // Verificar acceso directo
        $acceso = ModuloEmpresa::where('id_modulo', $this->id)
            ->where('id_empresa', $idEmpresa)
            ->where('activo', 1)
            ->first();

        if ($acceso) {
            return true;
        }

        // Verificar si algún padre tiene acceso con herencia
        if ($this->id_modulo_padre) {
            $padre = $this->padre;
            $accesoPadre = ModuloEmpresa::where('id_modulo', $padre->id)
                ->where('id_empresa', $idEmpresa)
                ->where('activo', 1)
                ->where('hereda_hijos', 1)
                ->first();

            if ($accesoPadre) {
                return true;
            }

            // Continuar verificando hacia arriba en la jerarquía
            return $padre->empresaTieneAcceso($idEmpresa);
        }

        return false;
    }
}
