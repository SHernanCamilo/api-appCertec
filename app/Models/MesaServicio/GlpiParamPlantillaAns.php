<?php

declare(strict_types=1);

namespace App\Models\MesaServicio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlpiParamPlantillaAns extends Model
{
    protected $table = 'glpi_param_plantilla_ans';

    protected $fillable = [
        'plantilla_id',
        'prioridad',
        'tiempo_asignacion',
        'unidad_asignacion',
        'tiempo_solucion',
        'unidad_solucion',
        'nombre_sla_solucion',
        'nombre_regla',
    ];

    protected $casts = [
        'plantilla_id' => 'integer',
        'tiempo_asignacion' => 'integer',
        'tiempo_solucion' => 'integer',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(GlpiParamPlantilla::class, 'plantilla_id');
    }
}
