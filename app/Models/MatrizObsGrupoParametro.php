<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatrizObsGrupoParametro extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla asociada al modelo
     */
    protected $table = 'matzobs_grupo_parametros';

    /**
     * Los atributos que son asignables en masa
     */
    protected $fillable = [
        'nombre',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Un grupo tiene muchos parámetros
     */
    public function parametros()
    {
        return $this->hasMany(MatrizObsParametro::class, 'id_grupo');
    }
}
