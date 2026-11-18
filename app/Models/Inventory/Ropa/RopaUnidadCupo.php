<?php

declare(strict_types=1);

namespace App\Models\Inventory\Ropa;

use App\Models\Config\ConfigUnidadFuncional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RopaUnidadCupo extends Model
{
    protected $table = 'inv_ropa_unidad_cupos';

    protected $fillable = [
        'id_unidad_funcional',
        'camas',
        'camas_medico',
        'personal',
        'aplica_estar_medico',
        'aplica_personal',
    ];

    protected $casts = [
        'id_unidad_funcional' => 'integer',
        'camas' => 'integer',
        'camas_medico' => 'integer',
        'personal' => 'integer',
        'aplica_estar_medico' => 'boolean',
        'aplica_personal' => 'boolean',
    ];

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(ConfigUnidadFuncional::class, 'id_unidad_funcional');
    }
}
