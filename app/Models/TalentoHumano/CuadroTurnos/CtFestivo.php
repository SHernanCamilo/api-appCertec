<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CtFestivo extends Model
{
    protected $table = 'humtal_ct_festivos';

    protected $fillable = [
        'fecha',
        'nombre',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'fecha'  => 'date',
        'estado' => 'boolean',
    ];

    /**
     * Verifica si una fecha (Y-m-d) es festivo registrado.
     * NO incluye domingos automáticamente — eso lo decide el servicio de cálculo.
     */
    public static function esFestivo(string $fecha): bool
    {
        return self::where('fecha', $fecha)
            ->where('estado', true)
            ->exists();
    }

    /**
     * Retorna todos los festivos en un rango de fechas como array de strings (Y-m-d).
     */
    public static function fechasEnRango(string $desde, string $hasta): array
    {
        return self::where('estado', true)
            ->whereBetween('fecha', [$desde, $hasta])
            ->pluck('fecha')
            ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }
}
