<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AntiClase extends Model
{
    protected $table = 'anti_clases';
    
    protected $fillable = [
        'id_tipo',
        'codigo',
        'nombre',
        'descripcion',
        'estado'
    ];
    
    protected $casts = [
        'estado' => 'boolean'
    ];
    
    /**
     * Relación: Una clase pertenece a un tipo
     */
    public function tipo()
    {
        return $this->belongsTo(AntiTipo::class, 'id_tipo');
    }
    
    /**
     * Relación: Una clase tiene muchas modalidades
     */
    public function modalidades()
    {
        return $this->hasMany(AntiModalidad::class, 'id_clase');
    }
    
    /**
     * Scope: Solo clases activas
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }
}
