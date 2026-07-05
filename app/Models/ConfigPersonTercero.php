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
        'id_cargo',
        'numero_identificacion',
        'nombre',
        'email',
        'tipo_identificacion',
        'unidad',
        'direccion',
        'telefono',
        'estado',
        'caso_glpi',
        'usuario_crea_id',
        'usuario_actualiza_id',
        'id_user',
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

    public function cargoRelacion()
    {
        return $this->belongsTo(Cargo::class, 'id_cargo', 'id_cargo');
    }
}
