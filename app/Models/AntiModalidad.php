<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AntiModalidad extends Model
{
    protected $table = 'anti_modalidades';
    
    protected $fillable = [
        'id_clase',
        'codigo',
        'nombre',
        'descripcion',
        'estado'
    ];
    
    protected $casts = [
        'estado' => 'boolean'
    ];
    
    /**
     * Relación: Una modalidad pertenece a una clase
     */
    public function clase()
    {
        return $this->belongsTo(AntiClase::class, 'id_clase');
    }
    
    /**
     * Scope: Solo modalidades activas
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }
}
