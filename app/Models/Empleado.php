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
        'id_unidad_funcional',
        'direccion',
        'telefono',
        'estado',
        'caso_glpi',
        'usuario_crea_id',
        'usuario_actualiza_id',
    ];

    protected $casts = [
        'estado'     => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function cargoRelacion()
    {
        return $this->belongsTo(Cargo::class, 'id_cargo', 'id_cargo');
    }

    public function unidadFuncional()
    {
        return $this->belongsTo(\App\Models\Finance\AntiUnidadFuncional::class, 'id_unidad_funcional');
    }

    /**
     * Retorna el nivel jerárquico del empleado según su cargo (1, 2 o 3).
     * Devuelve 3 (Operativo) como fallback si no tiene cargo asignado.
     */
    public function getNivelJerarquico(): int
    {
        return $this->cargoRelacion?->nivel_jerarquico ?? \App\Models\Cargo::NIVEL_OPERATIVO;
    }
}
