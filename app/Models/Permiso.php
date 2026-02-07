<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    use HasFactory;

    protected $table = 'seg_permisos';

    protected $fillable = [
        'id_modulo',
        'nombre',
        'codigo',
        'descripcion',
        'tipo',
        'icono',
        'orden',
        'estado'
    ];

    protected $casts = [
        'estado' => 'boolean',
        'orden' => 'integer'
    ];

    /**
     * Relación con el módulo
     */
    public function modulo()
    {
        return $this->belongsTo(Modulo::class, 'id_modulo');
    }

    /**
     * Scope para permisos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Scope para filtrar por módulo
     */
    public function scopePorModulo($query, $idModulo)
    {
        return $query->where('id_modulo', $idModulo);
    }

    /**
     * Scope para filtrar por tipo
     */
    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }
}
