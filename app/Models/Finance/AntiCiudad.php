<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AntiCiudad extends Model
{
    use HasFactory;
    protected $table = 'anti_ciudades';

    const TIPO_A = 'A'; // Bogotá, Medellín, Cali - capitales principales
    const TIPO_B = 'B'; // Neiva, Pasto, Pereira  - capitales intermedias
    const TIPO_C = 'C'; // Pitalito, Duitama      - municipios

    protected $fillable = ['nombre', 'departamento', 'tipo_ciudad', 'estado'];

    protected $casts = ['estado' => 'boolean'];

    public function scopeActivas($query)
    {
        return $query->where('estado', true);
    }

    public function scopeTipo($query, string $tipo)
    {
        return $query->where('tipo_ciudad', $tipo);
    }
}
