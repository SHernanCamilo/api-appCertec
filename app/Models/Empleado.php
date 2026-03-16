<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasFactory;

    protected $table = 'config_person_tercero';

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
        'usuario_actualiza_id'
    ];

    protected $casts = [
        'estado' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function cargoRelacion()
    {
        return $this->belongsTo(Cargo::class, 'id_cargo', 'id_cargo');
    }
}
