<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla
     */
    protected $table = 'config_ubi_sucursales';

    /**
     * Campos asignables en masa
     */
    protected $fillable = [
        'nombre',
        'prefijo',
        'id_Empresa'
    ];

    /**
     * Campos que deben ser casteados
     */
    protected $casts = [
        'id_Empresa' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relación con Empresa
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_Empresa');
    }

    /**
     * Scope para filtrar por empresa
     */
    public function scopePorEmpresa($query, $empresaId)
    {
        return $query->where('id_Empresa', $empresaId);
    }

    /**
     * Relación con Sedes
     */
    public function sedes()
    {
        return $this->hasMany(Sede::class, 'id_Sucursal');
    }
}
