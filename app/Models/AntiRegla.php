<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AntiRegla extends Model
{
    protected $table = 'anti_reglas';
    
    protected $fillable = [
        'id_concepto',
        'descripcion',
        'valor_tope',
        'estado'
    ];
    
    protected $casts = [
        'estado' => 'boolean',
        'valor_tope' => 'decimal:2'
    ];
    
    /**
     * Relación: Una regla pertenece a un concepto
     */
    public function concepto()
    {
        return $this->belongsTo(AntiConcepto::class, 'id_concepto');
    }
    
    /**
     * Scope: Solo reglas activas
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }
}
