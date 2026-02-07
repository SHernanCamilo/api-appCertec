<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatrizObsParametro extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla asociada al modelo
     */
    protected $table = 'matzobs_parametros';

    /**
     * Los atributos que son asignables en masa
     */
    protected $fillable = [
        'id_grupo',
        'nombre',
        'valor',
        'frecuencia',
        'rango_i',
        'rango_f',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'rango_i' => 'decimal:2',
        'rango_f' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación: Un parámetro pertenece a un grupo
     */
    public function grupo()
    {
        return $this->belongsTo(MatrizObsGrupoParametro::class, 'id_grupo');
    }
}
