<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    use HasFactory;

    protected $table = 'config_cargo';

    protected $primaryKey = 'id_cargo';

    protected $fillable = [
        'nombre_cargo',
        'nivel_jerarquico',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'estado'            => 'boolean',
        'nivel_jerarquico'  => 'integer',
    ];

    // Niveles según política de anticipos
    const NIVEL_ESTRATEGICO = 1; // Presidente, VP, Gerente, Directivo, Médico Especialista...
    const NIVEL_TACTICO     = 2; // Coordinador UF, Jefe de área, Analista profesional
    const NIVEL_OPERATIVO   = 3; // Técnicos, tecnólogos, auxiliares, asistentes

    public static function niveles(): array
    {
        return [
            self::NIVEL_ESTRATEGICO => 'Estratégico / Directivo',
            self::NIVEL_TACTICO     => 'Táctico (I y II)',
            self::NIVEL_OPERATIVO   => 'Operativo (I y II)',
        ];
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'id_cargo', 'id_cargo');
    }
}