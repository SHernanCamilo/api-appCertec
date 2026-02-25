<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfigPersonTercero extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla
     */
    protected $table = 'config_person_tercero';

    /**
     * Atributos asignables en masa
     */
    protected $fillable = [
        'id_empresa',
        'id_sucursal',
        'id_sede',
        'numero_identificacion',
        'nombre',
        'tipo_identificacion',
        'cargo',
        'unidad',
        'direccion',
        'telefono',
        'estado'
    ];

    /**
     * Atributos que deben ser casteados
     */
    protected $casts = [
        'estado' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Scope para filtrar solo activos
     */
    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Scope para buscar por número de identificación
     */
    public function scopePorIdentificacion($query, $numero)
    {
        return $query->where('numero_identificacion', $numero);
    }

    /**
     * Scope para buscar por nombre
     */
    public function scopeBuscarPorNombre($query, $nombre)
    {
        return $query->where('nombre', 'like', "%{$nombre}%");
    }

    /**
     * Relación con Empresa
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    /**
     * Relación con Sucursal
     */
    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    /**
     * Relación con Sede
     */
    public function sede()
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }
}
