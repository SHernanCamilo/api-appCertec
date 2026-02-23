<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatzobsTipoRam extends Model
{
    use HasFactory;

    protected $table = 'matzobs_tipos_ram';
    
    protected $fillable = [
        'nombre',
        'anio_lanzamiento'
    ];

    protected $casts = [
        'anio_lanzamiento' => 'integer'
    ];

    /**
     * Scope para buscar por nombre
     */
    public function scopeBuscarPorNombre($query, $nombre)
    {
        return $query->where('nombre', 'like', '%' . $nombre . '%');
    }
}
