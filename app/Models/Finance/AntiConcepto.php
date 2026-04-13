<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;

class AntiConcepto extends Model
{
    protected $table = 'anti_conceptos';
    
    protected $fillable = [
        'id_tipo',
        'id_clase',
        'id_modalidad',
        'estado'
    ];
    
    protected $casts = [
        'estado' => 'boolean'
    ];
    
    /**
     * Relación: Un concepto pertenece a un tipo
     */
    public function tipo()
    {
        return $this->belongsTo(AntiTipo::class, 'id_tipo');
    }
    
    /**
     * Relación: Un concepto pertenece a una clase
     */
    public function clase()
    {
        return $this->belongsTo(AntiClase::class, 'id_clase');
    }
    
    /**
     * Relación: Un concepto pertenece a una modalidad
     */
    public function modalidad()
    {
        return $this->belongsTo(AntiModalidad::class, 'id_modalidad');
    }
    
    /**
     * Relación: Un concepto tiene muchas reglas
     */
    public function reglas()
    {
        return $this->hasMany(AntiRegla::class, 'id_concepto');
    }
    
    /**
     * Scope: Solo conceptos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }
}
