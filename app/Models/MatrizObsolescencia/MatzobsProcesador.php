<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatzobsProcesador extends Model
{
    use HasFactory;

    protected $table = 'matzobs_procesadores';

    protected $fillable = [
        'nombre',
        'anio_lanzamiento',
    ];

    protected $casts = [
        'anio_lanzamiento' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope para buscar por nombre
     */
    public function scopeBuscarPorNombre($query, $nombre)
    {
        return $query->where('nombre', 'LIKE', "%{$nombre}%");
    }

    /**
     * Scope para filtrar por año
     */
    public function scopePorAnio($query, $anio)
    {
        return $query->where('anio_lanzamiento', $anio);
    }

    /**
     * Scope para ordenar por año descendente
     */
    public function scopeOrdenadoPorAnio($query)
    {
        return $query->orderBy('anio_lanzamiento', 'desc');
    }
}
