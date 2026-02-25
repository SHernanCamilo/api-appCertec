<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AntiTipo extends Model
{
    protected $table = 'anti_tipos';
    
    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'estado'
    ];
    
    protected $casts = [
        'estado' => 'boolean'
    ];
    
    /**
     * Relación: Un tipo tiene muchas clases
     */
    public function clases()
    {
        return $this->hasMany(AntiClase::class, 'id_tipo');
    }
    
    /**
     * Scope: Solo tipos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }
}
