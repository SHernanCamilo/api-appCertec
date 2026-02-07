<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sede extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla
     */
    protected $table = 'config_ubi_sede';

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'nombre',
        'id_Sucursal'
    ];

    /**
     * Campos que deben ser casteados
     */
    protected $casts = [
        'id_Sucursal' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relación con Sucursal
     */
    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_Sucursal');
    }

    /**
     * Scope para filtrar por sucursal
     */
    public function scopePorSucursal($query, $sucursalId)
    {
        return $query->where('id_Sucursal', $sucursalId);
    }

    /**
     * Scope para filtrar por empresa (a través de sucursal)
     */
    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->whereHas('sucursal', function($q) use ($empresaId) {
            $q->where('id_Empresa', $empresaId);
        });
    }
}
